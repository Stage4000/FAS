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
    /** Validate one complete column definition without executing SQL or a write probe. */
    public static function assertColumnMatches(string $createTable,string $expectedColumn):void
    {
        $expected=self::tokens($expectedColumn);
        if(count($expected)<2 || $expected[0][0]!=='word'){
            throw new RuntimeException('Expected column definition is invalid.');
        }
        $tokens=self::tokens($createTable);
        if(array_slice($tokens,0,2)!==[['word','create'],['word','table']]){
            throw new RuntimeException('Ordinary table definition is required.');
        }
        // sqlite_master stores an ordinary CREATE TABLE followed by one table name.
        if(!in_array($tokens[2][0]??null,['word','quoted'],true)
            || ($tokens[3]??null)!==['symbol','(']){
            throw new RuntimeException('Table column list is missing.');
        }
        $open=3;
        $depth=1;$part=[];$matches=[];$closed=false;
        for($i=$open+1,$count=count($tokens);$i<$count;$i++){
            $token=$tokens[$i];
            if($token===['symbol','(']){$depth++;}
            elseif($token===['symbol',')']){$depth--;}
            if($depth===0 || ($depth===1 && $token===['symbol',','])){
                if(($part[0]??null)===$expected[0]){$matches[]=$part;}
                $part=[];
                if($depth===0){$closed=true;break;}
                continue;
            }
            $part[]=$token;
        }
        $tail=array_slice($tokens,$i+1);
        $allowedTails=[[],[['word','without'],['word','rowid']],[['word','strict']],
            [['word','without'],['word','rowid'],['symbol',','],['word','strict']],
            [['word','strict'],['symbol',','],['word','without'],['word','rowid']]];
        if(!$closed || !in_array($tail,$allowedTails,true) || count($matches)!==1 || $matches[0]!==$expected){
            throw new RuntimeException('SQLite column definition is incompatible.');
        }
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
