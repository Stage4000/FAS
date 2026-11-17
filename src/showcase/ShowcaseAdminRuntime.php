<?php
declare(strict_types=1);
namespace FAS\Showcase;

/** Dormant trusted-bootstrap composition. Never starts a session, creates storage or enables routes. */
final class ShowcaseAdminRuntime
{
    public static function compose(\AdminAuth $auth, array $config, callable $enabled): ?array
    {
        // Closed gate must not inspect session, configuration, account or storage.
        if ($enabled() !== true) return null;
        if (session_status() !== PHP_SESSION_ACTIVE) throw new \RuntimeException('Locked administrator session required.');
        foreach (['session.use_strict_mode','session.use_only_cookies','session.cookie_secure','session.cookie_httponly'] as $setting) {
            if (ini_get($setting) !== '1') throw new \RuntimeException('Secure administrator session required.');
        }
        if (!in_array(strtolower((string)ini_get('session.cookie_samesite')), ['lax','strict'], true)) throw new \RuntimeException('Secure administrator cookie required.');
        $keys=array_keys($config); sort($keys);
        if ($keys !== ['consent_text','consent_version','csrf_session_key','database','max_photo_bytes','max_submissions','public_roots']) throw new \InvalidArgumentException('Explicit runtime configuration required.');
        foreach (['consent_text','consent_version','csrf_session_key','database'] as $key) if (!is_string($config[$key])) throw new \InvalidArgumentException('Invalid runtime value.');
        if (!is_array($config['public_roots']) || !is_int($config['max_submissions']) || !is_int($config['max_photo_bytes'])) throw new \InvalidArgumentException('Explicit storage limits required.');
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D',$config['csrf_session_key'])) throw new \InvalidArgumentException('Fixed session token key required.');
        $csrf=$_SESSION[$config['csrf_session_key']]??null;
        if (!is_string($csrf) || !preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D',$csrf)) throw new \RuntimeException('Current server session token required.');
        $authorization=new ShowcaseAdminAuthorization($auth);
        $authorization->actor(); // Reject unauthenticated access BEFORE private storage opens.
        $repository=ShowcasePrivateStorage::open($config['database'],$config['public_roots'],$authorization,$config['max_submissions'],$config['max_photo_bytes']);
        $request=new ShowcaseRequest($repository,$config['consent_version'],$config['consent_text'],static function(string $action) use($enabled): bool {
            return $action === 'moderate' && $enabled() === true;
        });
        $paths=[]; foreach(['queue','detail','photo','moderate'] as $endpoint) $paths[$endpoint]='/admin/showcase-'.$endpoint.'.php';
        return ['controller'=>new ShowcaseAdminController($repository,$request,[$authorization,'actor'],$enabled,$paths),'csrf'=>$csrf];
    }
}
