<?php
declare(strict_types=1);
namespace FAS\Showcase;

/** Trusted dormant composition. No schema, sessions, policies or route enablement are created. */
final class ShowcasePublicRuntime
{
    public static function compose(array $config, callable $enabled): ?ShowcasePublicController
    {
        if ($enabled() !== true) return null;
        if (session_status() !== PHP_SESSION_NONE) throw new \RuntimeException('Public runtime must be sessionless.');
        $keys=array_keys($config); sort($keys);
        if ($keys !== ['consent_hash','consent_version','database','public_roots']) throw new \InvalidArgumentException('Explicit public configuration required.');
        foreach (['consent_hash','consent_version','database'] as $key) if (!is_string($config[$key])) throw new \InvalidArgumentException('Invalid public configuration.');
        if (!is_array($config['public_roots'])
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D',$config['consent_version'])
            || !preg_match('/\A[a-f0-9]{64}\z/D',$config['consent_hash'])) throw new \InvalidArgumentException('Reviewed consent identity required.');
        $repository=ShowcasePrivateStorage::openReadOnly($config['database'],$config['public_roots']);
        return new ShowcasePublicController($repository,$enabled,$config['consent_version'],$config['consent_hash'],'/showcase-photo.php');
    }
}
