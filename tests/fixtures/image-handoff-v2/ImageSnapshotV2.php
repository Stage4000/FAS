<?php
declare(strict_types=1);
/** Inactive v2 snapshot serializer. Pure values only; no DB, app, network or file access. */
final class ImageSnapshotV2
{
    public static function encode(mixed $id, mixed $revision, mixed $deleted, mixed $primary = null, mixed $gallery = null): array
    {
        if (!is_int($id) || $id < 1 || !is_int($revision) || $revision < 1 || !is_bool($deleted)) {
            throw new InvalidArgumentException('Invalid identity, revision or tombstone');
        }
        // Enforce exactly the same byte-based input bounds as v2 Python reference.
        if (!$deleted) {
            foreach ([[$primary,2048],[$gallery,131072]] as [$value,$bound]) {
                if ($value !== null && (!is_string($value) || strlen($value)>$bound)) {
                    throw new InvalidArgumentException('Invalid image snapshot field');
                }
            }
        } elseif ($primary !== null || $gallery !== null) {
            throw new InvalidArgumentException('Tombstone cannot carry live fields');
        }
        $value=['version'=>1,'product_id'=>$id,'revision'=>$revision,'deleted'=>$deleted];
        if (!$deleted) { $value['image_url']=$primary; $value['images']=$gallery; }
        ksort($value,SORT_STRING);
        $payload=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS);
        if (strlen($payload)>1048576) throw new LengthException('Serialized snapshot exceeds bound');
        return ['snapshot_json'=>$payload,'snapshot_sha256'=>hash('sha256',$payload)];
    }
}
