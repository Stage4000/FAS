<?php
declare(strict_types=1);
namespace FAS\Showcase;

/** Dormant response composition. No route, session, storage provisioning or enablement. */
final class ShowcasePublicController
{
    private $repository; private $enabled; private $version; private $hash; private $photoPath;
    public function __construct(ShowcaseRepository $repository, callable $enabled, string $consentVersion, string $consentHash, string $photoPath)
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $consentVersion)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $consentHash)
            || !preg_match('~\A/[A-Za-z0-9][A-Za-z0-9_./-]{0,199}\z~D', $photoPath)
            || strpos($photoPath, '..') !== false) {
            throw new \InvalidArgumentException('Explicit trusted public configuration required.');
        }
        $this->repository=$repository; $this->enabled=$enabled;
        $this->version=$consentVersion; $this->hash=$consentHash; $this->photoPath=$photoPath;
    }
    /** Endpoint is fixed server dispatch, configuration must never come from the request. */
    public function handle(string $endpoint, array $server, array $query, array $post): array
    {
        try {
            if (($this->enabled)() !== true || !in_array($endpoint,['gallery','photo'],true)) return self::response(404,'Not found.');
            if (($server['REQUEST_METHOD']??null) !== 'GET') return self::response(405,'Method not allowed.',['Allow'=>'GET']);
            if ($post) return self::response(404,'Not found.');
            $policy=function (array $snapshot): bool {
                return ($this->enabled)() === true && $snapshot['consent_version'] === $this->version
                    && hash_equals($this->hash,$snapshot['consent_text_sha256']);
            };
            if ($endpoint === 'gallery') {
                if ($query) return self::response(404,'Not found.');
                $body=ShowcaseViews::gallery($this->repository->approvedGallery($policy,20),$this->photoPath);
                $response=self::response(200,$body);
            } else {
                if (array_keys($query)!==['id'] || !is_string($query['id']) || !preg_match('/\A[a-f0-9]{32}\z/D',$query['id'])) return self::response(404,'Not found.');
                $photo=$this->repository->approvedPhoto($query['id'],$policy);
                $response=self::response(200,$photo['body'],$photo['headers']);
            }
            // Last gate read before returning bytes; already delivered/in-flight bytes cannot be revoked.
            return ($this->enabled)() === true ? $response : self::response(404,'Not found.');
        } catch (\OutOfBoundsException $error) { return self::response(404,'Not found.'); }
        catch (\Throwable $error) { return self::response(503,'Customer photos are temporarily unavailable.'); }
    }
    private static function response(int $status,string $body,array $extra=[]): array
    {
        return ['status'=>$status,'body'=>$body,'headers'=>array_merge([
            'Content-Type'=>'text/html; charset=utf-8','Cache-Control'=>'private, no-store','Pragma'=>'no-cache',
            'X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer',
            'Content-Security-Policy'=>"default-src 'none'; img-src 'self'; frame-ancestors 'none'; base-uri 'none'"
        ],$extra)];
    }
}
