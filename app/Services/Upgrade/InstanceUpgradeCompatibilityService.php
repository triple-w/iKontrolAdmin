<?php
namespace App\Services\Upgrade;
final class InstanceUpgradeCompatibilityService {
 public function evaluate(array$detection,array$items,IkontrolVersionDefinition$definition):array{$reasons=[];$compatibility='COMPATIBLE';$source=$detection['ikontrol_version']??$detection['rise_version']??null;$supported=$definition->manifest['supported_upgrade_sources']??[];if(!$source){$compatibility='MANUAL_REVIEW';$reasons[]='VERSION_NOT_RECOGNIZED';}elseif($supported&&!$this->supported($source,$supported)){$compatibility='INCOMPATIBLE';$reasons[]='SOURCE_OUTSIDE_SUPPORTED_PATH';}
  foreach($items as$item){if(($item['severity']??null)==='CRITICAL'){$compatibility=($item['details_json']['corruption']??false)?'INCOMPATIBLE':'MANUAL_REVIEW';$reasons[]=$item['details_json']['difference']??$item['status'];}elseif(in_array($item['status']??'', ['CUSTOMIZED','DIFFERENT','PARTIAL','INCONSISTENT'],true)&&$compatibility==='COMPATIBLE'){$compatibility='MANUAL_REVIEW';$reasons[]=$item['details_json']['difference']??$item['status'];}}
  return['compatibility'=>$compatibility,'reasons'=>array_values(array_unique($reasons))];}
 private function supported(string$version,array$ranges):bool{foreach($ranges as$range){if($range==='*'||$range===$version)return true;if(str_ends_with($range,'.x')&&str_starts_with($version,substr($range,0,-1)))return true;}return false;}
}
