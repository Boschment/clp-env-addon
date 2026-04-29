<?php

namespace App\Controller\Frontend;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Entity\Manager\SiteManager;
use App\Service\Logger;

class EnvController extends Controller
{
    private const DATA_DIR = '/opt/clp-env-addon/data';
    private const POOL_GLOB = '/etc/php/*/fpm/pool.d/%s.conf';
    private const BLOCK_BEGIN = '; BEGIN clp-env-addon';
    private const BLOCK_END   = '; END clp-env-addon';

    private SiteManager $siteEntityManager;

    public function __construct(
        TranslatorInterface $translator,
        Logger $logger,
        SiteManager $siteEntityManager
    ) {
        parent::__construct($translator, $logger);
        $this->siteEntityManager = $siteEntityManager;
    }

    public function index(Request $request, string $domainName): Response
    {
        $site = $this->siteEntityManager->findOneByDomainName($domainName);
        if (null === $site) {
            return $this->redirect($this->generateUrl('clp_sites'));
        }

        return $this->render('Frontend/Site/env.html.twig', [
            'site'       => $site,
            'user'       => $this->getUser(),
            'formErrors' => [],
            'variables'  => $this->loadVariables($domainName),
        ]);
    }

    public function save(Request $request, string $domainName): Response
    {
        $site = $this->siteEntityManager->findOneByDomainName($domainName);
        if (null === $site) {
            return $this->redirect($this->generateUrl('clp_sites'));
        }

        $this->checkCsrfToken($request, 'env-save');

        $key   = trim((string) $request->request->get('key', ''));
        $value = (string) $request->request->get('value', '');

        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
            $this->addFlash('error', 'Invalid key. Use only A-Z, 0-9 and _, and start with a letter or _.');
            return $this->redirect($this->generateUrl('clp_site_env', ['domainName' => $domainName]));
        }

        $vars = $this->loadVariables($domainName);
        $vars[$key] = $value;
        $this->saveVariables($domainName, $vars);
        $this->applyVariables($domainName, $vars);

        $this->addFlash('success', sprintf('Variable %s saved.', $key));
        return $this->redirect($this->generateUrl('clp_site_env', ['domainName' => $domainName]));
    }

    public function delete(Request $request, string $domainName): Response
    {
        $site = $this->siteEntityManager->findOneByDomainName($domainName);
        if (null === $site) {
            return $this->redirect($this->generateUrl('clp_sites'));
        }

        $this->checkCsrfToken($request, 'env-delete');

        $key  = trim((string) $request->request->get('key', ''));
        $vars = $this->loadVariables($domainName);

        if (isset($vars[$key])) {
            unset($vars[$key]);
            $this->saveVariables($domainName, $vars);
            $this->applyVariables($domainName, $vars);
            $this->addFlash('success', sprintf('Variable %s removed.', $key));
        }

        return $this->redirect($this->generateUrl('clp_site_env', ['domainName' => $domainName]));
    }

    private function dataFile(string $domainName): string
    {
        return self::DATA_DIR . '/' . $domainName . '.json';
    }

    private function loadVariables(string $domainName): array
    {
        $file = $this->dataFile($domainName);
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function saveVariables(string $domainName, array $vars): void
    {
        if (!is_dir(self::DATA_DIR)) {
            @mkdir(self::DATA_DIR, 0750, true);
        }
        ksort($vars);
        file_put_contents(
            $this->dataFile($domainName),
            json_encode($vars, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Apply variables to the site, only to the runtimes actually present:
     *   - PHP-FPM pool (if a pool conf exists for this site user): write
     *     env[KEY]="value" block, then reload php-fpm.
     *   - PM2 (if pm2 is installed for the site user): restart any PM2 apps
     *     whose cwd is under the site root, with vars exported in the restart
     *     shell. No .env or ecosystem file is written.
     */
    private function applyVariables(string $domainName, array $vars): void
    {
        $resolved = $this->resolveSite($domainName);
        if (null === $resolved) {
            return;
        }
        [$siteUser, $siteRoot] = $resolved;

        $hasPhp = $this->siteHasPhpFpm($siteUser);

        if ($hasPhp) {
            $this->updatePhpFpmPools($vars, $siteUser);
        }

        // The PM2 helper detects pm2 itself and exits 10 if not installed,
        // 11 if installed but no matching apps, 0 if it restarted something.
        $pm2Status = $this->reloadPm2($domainName, $siteUser, $siteRoot);

        if (!$hasPhp && 10 === $pm2Status) {
            $this->addFlash('warning', 'Variable saved, but neither a PHP-FPM pool nor PM2 was detected for this site — nothing was applied to a running runtime.');
        }
    }

    private function siteHasPhpFpm(string $siteUser): bool
    {
        $pools = glob(sprintf(self::POOL_GLOB, $siteUser));
        return is_array($pools) && count($pools) > 0;
    }

    private function resolveSite(string $domainName): ?array
    {
        // Sanitize: only allow domain-shaped strings, no traversal.
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $domainName)) {
            return null;
        }
        $matches = glob('/home/*/htdocs/' . $domainName);
        if (!$matches || !is_dir($matches[0])) {
            return null;
        }
        $siteRoot = $matches[0];
        $user = trim((string) shell_exec('stat -c %U ' . escapeshellarg($siteRoot)));
        if ('' === $user) {
            return null;
        }
        return [$user, $siteRoot];
    }

    private function reloadPm2(string $domainName, string $siteUser, string $siteRoot): int
    {
        $cmd = sprintf(
            'sudo /usr/local/bin/clp-env-pm2-reload %s %s %s 2>&1',
            escapeshellarg($domainName),
            escapeshellarg($siteUser),
            escapeshellarg($siteRoot)
        );
        exec($cmd, $out, $rc);

        // 10 = pm2 not installed (treat as "not a node site", silent skip)
        // 11 = pm2 installed but no apps under site root (silent skip)
        //  0 = restart attempted
        if (!in_array($rc, [0, 10, 11], true)) {
            $this->addFlash('warning', 'PM2 reload helper exited with ' . $rc . ': ' . implode(' ', $out));
        }

        return $rc;
    }

    private function writeAsUser(string $destPath, string $contents, string $owner, string $mode): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'clpenv_');
        file_put_contents($tmp, $contents);

        // Use install(1) to atomically move with correct owner+mode.
        $cmd = sprintf(
            'sudo install -o %s -g %s -m %s %s %s',
            escapeshellarg($owner),
            escapeshellarg($owner),
            escapeshellarg($mode),
            escapeshellarg($tmp),
            escapeshellarg($destPath)
        );
        exec($cmd . ' 2>&1', $out, $rc);
        @unlink($tmp);

        if (0 !== $rc) {
            $this->addFlash('warning', 'Could not write ' . $destPath . ': ' . implode(' ', $out));
        }
    }

    private function updatePhpFpmPools(array $vars, ?string $siteUser): void
    {
        if (null === $siteUser || '' === $siteUser) {
            return;
        }

        $pools = glob(sprintf(self::POOL_GLOB, $siteUser));
        if (!$pools) {
            return;
        }

        $block = [self::BLOCK_BEGIN];
        foreach ($vars as $key => $value) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
            $block[] = sprintf('env[%s] = "%s"', $key, $escaped);
        }
        $block[] = self::BLOCK_END;
        $blockStr = implode("\n", $block) . "\n";

        foreach ($pools as $pool) {
            $current = (string) shell_exec('sudo cat ' . escapeshellarg($pool) . ' 2>/dev/null');
            if ('' === $current) {
                continue;
            }

            $pattern = '/' . preg_quote(self::BLOCK_BEGIN, '/') . '.*?' . preg_quote(self::BLOCK_END, '/') . "\n?/s";
            if (preg_match($pattern, $current)) {
                $updated = preg_replace($pattern, $blockStr, $current);
            } else {
                $updated = rtrim($current, "\n") . "\n\n" . $blockStr;
            }

            $this->writeAsUser($pool, $updated, 'root', '0644');

            if (preg_match('#/etc/php/([0-9.]+)/fpm/#', $pool, $m)) {
                $service = 'php' . $m[1] . '-fpm';
                exec('sudo systemctl reload ' . escapeshellarg($service) . ' 2>&1');
            }
        }
    }
}
