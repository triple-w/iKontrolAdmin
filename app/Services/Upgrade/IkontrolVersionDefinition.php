<?php
namespace App\Services\Upgrade;
use RuntimeException;
final class IkontrolVersionDefinition {
 public function __construct(public readonly string $version,public readonly array $manifest,public readonly array $database,public readonly array $settings,public readonly array $files){}
 public static function load(string$version):self{if(!preg_match('/\A[0-9A-Za-z._-]+\z/',$version))throw new RuntimeException('Versión objetivo inválida.');$root=rtrim(config('ikontrol.version_sources.manifest_root',storage_path('ikontrol-versions')),'/\\').DIRECTORY_SEPARATOR.$version;$read=static function(string$name)use($root):array{$path=$root.DIRECTORY_SEPARATOR.$name;if(!is_file($path))throw new RuntimeException("No existe el manifiesto requerido: {$name}");$decoded=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);return is_array($decoded)?$decoded:[];};$manifest=$read('manifest.json');if(($manifest['version']??null)!==$version)throw new RuntimeException('La versión del manifiesto no coincide con el directorio.');return new self($version,$manifest,$read('database.json'),$read('settings.json'),$read('files.json'));}
}
