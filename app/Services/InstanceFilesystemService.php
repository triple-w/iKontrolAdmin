<?php
namespace App\Services;
use Illuminate\Support\Facades\File;use InvalidArgumentException;use RuntimeException;
class InstanceFilesystemService {
 public function validateSlug(string $slug):bool{return(bool)preg_match('/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/',$slug)&&strlen($slug)<=40;}
 public function folderName(string $slug):string{if(!$this->validateSlug($slug))throw new InvalidArgumentException('Slug inválido.');return $slug.config('ikontrol.folder_suffix');}
 public function path(string $slug):string{return rtrim(config('ikontrol.instances_root'),'/\\').DIRECTORY_SEPARATOR.$this->folderName($slug);}
 public function validatePath(string $slug):bool{$path=$this->path($slug);$root=rtrim(config('ikontrol.instances_root'),'/\\').DIRECTORY_SEPARATOR;return str_starts_with($path,$root)&&!str_contains($slug,'..');}
 public function folderExists(string $slug):bool{return is_dir($this->path($slug));}
 public function rootWritable():bool{$root=config('ikontrol.instances_root');return is_dir($root)&&is_writable($root);}
 public function createFolder(string $slug):string{if(!$this->validatePath($slug)||$this->folderExists($slug))throw new RuntimeException('La carpeta no es segura o ya existe.');if(!mkdir($path=$this->path($slug),0750,false))throw new RuntimeException('No fue posible crear la carpeta.');return $path;}
 public function removeEmptyFolder(string $slug):bool{$path=$this->path($slug);if(!is_dir($path)||count(scandir($path))!==2)return false;return rmdir($path);}
 public function removeManagedFolder(string $slug):bool{if(!$this->validatePath($slug))throw new RuntimeException('La ruta no es segura.');$path=$this->path($slug);if(!is_dir($path))return true;if(is_link($path))throw new RuntimeException('No se permite eliminar un symlink.');return File::deleteDirectory($path);}
}
