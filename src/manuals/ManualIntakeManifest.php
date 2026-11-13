<?php
declare(strict_types=1);
namespace FAS\Manuals;

/** Draft metadata preparation only. Never opens inventory paths or a catalog. */
final class ManualIntakeManifest
{
    private const COLUMNS = ['path','title','manufacturer','model','years','language','description'];
    private const STATUSES = ['candidate','video','non_pdf','empty','oversized','read_budget','changed','bad_header','unreadable','symlink','non_regular'];
    /** Decode valid bounded JSON without silently accepting duplicate object keys. */
    public static function decodeInput(string $raw): array
    {
        if (strlen($raw)>33554432) throw new \InvalidArgumentException('Input is too large.');
        $decoded=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new \InvalidArgumentException('Expected an object.');
        $stack=[];$length=strlen($raw);
        // Syntax/depth are already validated. Walk string boundaries so braces,
        // commas and escaped key spellings inside CSV text cannot alter state.
        for ($i=0;$i<$length;$i++) {
            $c=$raw[$i];
            if ($c==='{' || $c==='[') {$stack[]=['object'=>$c==='{','key'=>$c==='{','seen'=>[]];continue;}
            if ($c==='}' || $c===']') {array_pop($stack);continue;}
            $top=count($stack)-1;
            if ($c===',') {if ($top>=0 && $stack[$top]['object']) $stack[$top]['key']=true;continue;}
            if ($c!=='"') continue;
            $start=$i++;
            while ($i<$length && $raw[$i]!=='"') {if ($raw[$i]==='\\') $i++;$i++;}
            if ($top>=0 && $stack[$top]['object'] && $stack[$top]['key']) {
                $key="\0".json_decode(substr($raw,$start,$i-$start+1),true,32,JSON_THROW_ON_ERROR);
                if (isset($stack[$top]['seen'][$key])) throw new \InvalidArgumentException('Duplicate JSON key.');
                $stack[$top]['seen'][$key]=true;$stack[$top]['key']=false;
            }
        }
        return $decoded;
    }
    /** Reject unterminated quotes and text after a closing quote before fgetcsv. */
    private static function csvSyntax(string $csv): void
    {
        if (substr($csv,0,3)==="\xEF\xBB\xBF") $csv=substr($csv,3);
        $state='start';$length=strlen($csv);
        for ($i=0;$i<$length;$i++) {
            $c=$csv[$i];
            if ($state==='quoted') {
                if ($c==='"') {
                    if ($i+1<$length && $csv[$i+1]==='"') {$i++;continue;}
                    $state='closed';
                }
                continue;
            }
            if ($c===',' || $c==="\n" || $c==="\r") {$state='start';continue;}
            if ($state==='closed' || ($state==='plain' && $c==='"')) throw new \InvalidArgumentException('Invalid CSV quoting.');
            $state=($state==='start' && $c==='"')?'quoted':'plain';
        }
        if ($state==='quoted') throw new \InvalidArgumentException('Unterminated CSV quote.');
    }
    public static function prepare(array $inventory, string $csv): array
    {
        if (($inventory['version'] ?? null)!==1 || !is_bool($inventory['complete'] ?? null) || !is_array($inventory['files'] ?? null) || !array_is_list($inventory['files']) || count($inventory['files'])>100000 || !is_array($inventory['errors'] ?? null) || ($inventory['complete'] && $inventory['errors'])) throw new \InvalidArgumentException('Invalid inventory envelope.');
        if (strlen($csv)>16777216 || !preg_match('//u',$csv)) throw new \InvalidArgumentException('Invalid metadata CSV.');
        $csv=preg_replace('/\A\xEF\xBB\xBF/','',$csv);
        self::csvSyntax($csv);
        $candidates=[];$seen=[];$total=0;$excluded=[];
        foreach ($inventory['files'] as $file) {
            if (!is_array($file)) throw new \InvalidArgumentException('Invalid inventory record.');
            $path=$file['path'] ?? null;$status=$file['status'] ?? null;
            if (!is_string($path) || $path==='' || strlen($path)>16384 || strpos($path,"\0")!==false || !preg_match('//u',$path) || $path[0]==='/' || preg_match('~(?:^|/)(?:\.{1,2})?(?:/|$)~',$path) || isset($seen["\0".$path]) || !in_array($status,self::STATUSES,true)) throw new \InvalidArgumentException('Invalid inventory path or status.');
            $seen["\0".$path]=true;
            if ($inventory['complete'] && in_array($status,['read_budget','changed','unreadable'],true)) throw new \InvalidArgumentException('Inconsistent inventory completeness.');
            if ($status!=='candidate') {$excluded[$status]=($excluded[$status] ?? 0)+1;continue;}
            $size=$file['bytes'] ?? null;$hash=$file['sha256'] ?? null;
            if (!is_int($size) || $size<1 || $size>52428800 || !is_string($hash) || !preg_match('/\A[0-9a-f]{64}\z/D',$hash)) throw new \InvalidArgumentException('Invalid candidate identity.');
            $candidates["\0".$path]=['path'=>$path,'bytes'=>$size,'sha256'=>$hash];$total+=$size;
        }
        if (($inventory['candidate_count'] ?? null)!==count($candidates) || ($inventory['candidate_bytes'] ?? null)!==$total) throw new \InvalidArgumentException('Inconsistent inventory totals.');
        $stream=fopen('php://temp/maxmemory:16777216','w+b');
        if ($stream===false) throw new \RuntimeException('Unable to prepare metadata.');
        try {
            if (fwrite($stream,$csv)!==strlen($csv) || !rewind($stream)) throw new \RuntimeException('Unable to prepare metadata.');
            $header=fgetcsv($stream,0,',','"','');
            if (!is_array($header) || count($header)!==count(self::COLUMNS) || count(array_unique($header))!==count(self::COLUMNS) || array_diff($header,self::COLUMNS)) throw new \InvalidArgumentException('Use exactly the documented CSV columns.');
            $drafts=[];$errors=[];$mapped=[];$row=1;
            while (($values=fgetcsv($stream,0,',','"',''))!==false) {
                if (++$row>100001) throw new \InvalidArgumentException('Too many metadata rows.');
                if ($values===[null]) continue;
                if (count($values)!==count($header)) {$errors[]=['row'=>$row,'reason'=>'column_count'];continue;}
                $data=array_combine($header,$values);$path=$data['path'];
                if (!isset($candidates["\0".$path])) {$errors[]=['row'=>$row,'reason'=>'not_inventory_candidate'];continue;}
                if (isset($mapped["\0".$path])) {$errors[]=['row'=>$row,'reason'=>'duplicate_path'];continue;}
                $mapped["\0".$path]=true;
                unset($data['path']);$data['rights']='unverified';$data['rights_note']='';$data['source_url']='';
                if ($data['language']==='') $data['language']='English';
                try {$metadata=ManualLibrary::validate($data,false);}
                catch (\InvalidArgumentException $e) {$errors[]=['row'=>$row,'reason'=>'invalid_metadata'];continue;}
                $drafts[]=['inventory_identity'=>$candidates["\0".$path],'metadata'=>$metadata,'publication_requested'=>false];
            }
            foreach ($candidates as $key=>$file) if (!isset($mapped[$key])) $errors[]=['path'=>$file['path'],'reason'=>'metadata_missing'];
            usort($drafts,static function($a,$b){return strcmp($a['inventory_identity']['path'],$b['inventory_identity']['path']);});
            ksort($excluded);
            return ['version'=>1,'draft_only'=>true,'inventory_complete'=>$inventory['complete'],'ready_for_review'=>$inventory['complete'] && !$errors,'draft_count'=>count($drafts),'candidate_count'=>count($candidates),'excluded_counts'=>$excluded,'drafts'=>$drafts,'errors'=>$errors,'limitations'=>['Preparation only; no originals opened, rehashed, copied, imported or published.','Inventory identity is an untrusted observation, not a storage key or verified current file.','All rights remain unverified. Publication review and owner release are separate.','Ready for review is not import authorization, complete PDF validation or a capacity guarantee.','Do not execute CSV values as formulas or render metadata without output escaping.']];
        } finally {fclose($stream);}
    }
}
