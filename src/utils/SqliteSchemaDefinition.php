<?php
declare(strict_types=1);
namespace FAS\Utils;

use RuntimeException;

/** Compare a SQLite stored definition against explicit trusted DDL without executing a probe. */
final class SqliteSchemaDefinition
{
    public static function assertMatches(string $actual,string $expected):void
    {
        if(self::tokens($actual)!==self::tokens($expected)){throw new RuntimeException('SQLite schema definition is incompatible.');}
    }
    private static function tokens(string $sql):array
    {
        if($sql===''||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$sql)){throw new RuntimeException('Invalid control byte in schema definition.');}
        $tokens=[];$length=strlen($sql);$i=0;
        while($i<$length){
            $char=$sql[$i];
            if(str_contains(" \t\r\n",$char)){$i++;continue;}
            if(substr($sql,$i,2)==='--'){$end=$i+2;while($end<$length && $sql[$end]!=="\n"){$end++;}$i=$end;continue;}
            if(substr($sql,$i,2)==='/*'){$end=strpos($sql,'*/',$i+2);if($end===false){throw new RuntimeException('Unterminated schema comment.');}$i=$end+2;continue;}
            if($char==="'"||$char==='"'||$char==='`'||$char==='['){
                $start=$i;$quote=$char;$close=$quote==='['?']':$quote;$i++;$closed=false;
                while($i<$length){$part=$sql[$i++];if($part===$close){if($quote!=='['&&$i<$length&&$sql[$i]===$close){$i++;continue;}$closed=true;break;}}
                if(!$closed){throw new RuntimeException('Unterminated schema quoted token.');}
                $tokens[]=[$quote==="'"?'literal':'quoted',substr($sql,$start,$i-$start)];continue;
            }
            if(preg_match('/[A-Za-z_]/',$char)===1){$start=$i++;while($i<$length&&preg_match('/[A-Za-z0-9_$]/',$sql[$i])===1){$i++;}$tokens[]=['word',strtolower(substr($sql,$start,$i-$start))];continue;}
            if($char>='0'&&$char<='9'){$start=$i++;while($i<$length&&$sql[$i]>='0'&&$sql[$i]<='9'){$i++;}$tokens[]=['number',substr($sql,$start,$i-$start)];continue;}
            $operator=substr($sql,$i,3)==='->>'?'->>':substr($sql,$i,2);
            if(in_array($operator,['<=','>=','<>','!=','==','||','->','->>','<<','>>'],true)){$tokens[]=['symbol',$operator];$i+=strlen($operator);continue;}
            if(str_contains('(),;><=+-*/.%&|~',$char)){$tokens[]=['symbol',$char];$i++;continue;}
            throw new RuntimeException('Unsupported schema token.');
        }
        if(end($tokens)===['symbol',';']){array_pop($tokens);}
        if(array_slice($tokens,0,5)===[['word','create'],['word','table'],['word','if'],['word','not'],['word','exists']]){array_splice($tokens,2,3);}
        if(!$tokens){throw new RuntimeException('Empty schema definition.');}return $tokens;
    }
}
