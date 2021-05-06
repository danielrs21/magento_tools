<?php 

/**
 * Script para depurar imagenes de productos de magento
 * 
 * @author Daniel Rodríguez
 */

use Magento\Framework\App\Bootstrap;
use Symfony\Component\Console\Helper\Table;
use Magento\Catalog\Api\CategoryLinkManagementInterface;

require __DIR__ . '/app/bootstrap.php';

if(php_sapi_name() != 'cli') {
    die('No existe la pagina solicitada');
}

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

$state = $objectManager->get('Magento\Framework\App\State');
$state->setAreaCode('adminhtml');

$deleted = 0;
$read = 0;
echo 'Iniciando revision de directorio imagenes'.PHP_EOL;
check_from_file(); 

/**
 * Lee el directorio de imagenes de productos y verifica si existe producto asociado en magento. 
 * - Si no existe producto asociado se elimina del disco. 
 */
function check_from_file($dir=null) {

    $default = "pub/media/catalog/product";

    if(!$dir) $dir = $default;
    
    $gestor = opendir($dir); 

    while (false !== ($entrada = readdir($gestor))) {

        /* Verificara si es un directorio sino procesa los archivos */
        if (is_dir($dir.'/'.$entrada))
        {
            if($entrada == '..' || $entrada == '.' || $entrada == 'placeholder') {
                // Se omiten directorios de retorno y no relacionados con productos magento
            } else {
                echo 'Se procesa subdirectorio: '.$dir.'/'.$entrada.PHP_EOL; 
                if($dir == $default){
                    if($entrada == 'cache'){
                        check_from_file($dir.'/'.$entrada); 
                    }
                } else {
                    check_from_file($dir.'/'.$entrada); 
                }
            }
        }
        else
        {
            check_file($entrada,$dir,$default); 
        }
    }
    echo 'Se han revisado: '.$GLOBALS['read'].' imágenes'.PHP_EOL;
    echo '- Se encontraron y eliminaron '.$GLOBALS['deleted'].' imágenes sin asociación con productos'.PHP_EOL;  

}

/**
 * Verifica un archivo 
 * - Busca en la DB para verificar si esta asociado a producto
 * - Si no lo consigue en DB lo elimina del disco 
 */
function check_file($entrada,$dir,$default){
    
    $GLOBALS['read']++; 
    $objectManager  = \Magento\Framework\App\ObjectManager::getInstance(); 
    $resource       = $objectManager->get('Magento\Framework\App\ResourceConnection');
    $connection     = $resource->getConnection();

    $ruta = str_replace($default, '', $dir.'/'.$entrada); 

    $parts = explode('/',$ruta);

    while (count($parts) > 3) {
        $remove = array_shift($parts); 
    }
    $archivo = '/'.implode('/',$parts); 

    // Buscar registro de imagenes en DB sin producto asociado para borrar archivo en disco 
    $sql = "SELECT value FROM catalog_product_entity_media_gallery WHERE value ='$archivo'";
    $imagenes = $connection->fetchAll($sql);
    if( count($imagenes) == 0) {
        if(unlink($dir.'/'.$entrada)) {
            $GLOBALS['deleted']++; 
            echo 'EXITO: achivo eliminado: '.$dir.'/'.$entrada.PHP_EOL;             
        } else {
            echo 'ERROR: eliminando archivo: '.$dir.'/'.$entrada.PHP_EOL;  
        }
    }
}

/**
 * Verifica imagenes registradas en db y verifica si estan asociadas a productos
 * - Las imagenes no asociadas a productos se eliminan del disco
 * - Al finalizar se depura la tabla catalog_product_entity_media_gallery eliminando los registros de imagenes eliminadas
 */
function check_from_db(){

    $objectManager  = \Magento\Framework\App\ObjectManager::getInstance(); 
    $resource       = $objectManager->get('Magento\Framework\App\ResourceConnection');
    $connection     = $resource->getConnection();

    // Buscar registro de imagenes en DB sin producto asociado para borrar archivo en disco 
    $sql = "
            SELECT 
                a.value, 
                (select count(*) FROM catalog_product_entity_media_gallery_value_to_entity WHERE value_id = a.value_id) as productos
            FROM 
                catalog_product_entity_media_gallery a 
            WHERE
                (select count(*) from catalog_product_entity_media_gallery_value_to_entity where value_id = a.value_id) = 0
            ";
    $imagenes = $connection->fetchAll($sql);

    $ruta = 'pub/media/catalog/product';
    $i = 0; $f = 0; $eliminados = 0; $omitidos = 0;
    $testMode = false; 
    foreach ($imagenes as $imagen) {
        $i++; $f++; 
        $archivo = $ruta.$imagen['value'];
        if(file_exists($archivo)) {
            if(!$testMode) unlink($archivo);
            $eliminados++;  
            echo $archivo." ---> \e[1;37;42m ARCHIVO BORRADO \e[0m\n".PHP_EOL;
        } else {
            $omitidos++;
            echo $archivo." ---> \e[1;37;41m NO EXISTE \e[0m\n".PHP_EOL;
        }
        if($f == 1000) {
            $f = 0;
            echo '.........................................................................'.PHP_EOL;
            sleep(5); 
        }
    }

    /* Se eliminan registros de imagenes inexistentes de la db */ 
    if( $eliminados > 0 ) {
        $query = "
            DELETE FROM catalog_product_entity_media_gallery 
            WHERE value_id not in(select value_id from catalog_product_entity_media_gallery_value_to_entity)";
        $connection->query($query);
    }

    echo 'Proceso terminado'.PHP_EOL;
    echo 'Se eliminaron: '.$eliminados.' archivos de imagenes sin productos asociados'.PHP_EOL;
    echo 'Se encontraron: '.$omitidos.' registros en la DB de archivos de imagenes que no existen en directorio';

}
?>