<?php
/**
 * Script to import/update stocks/inventory/qty in bulk via CSV
 *
 * @author Raj KB <magepsycho@gmail.com>
 * @website https://www.magepsycho.com
 * @extension MassImporterPro: Pricing Import Script - https://www.magepsycho.com/magento2-mass-regular-special-tier-group-price-importer.html
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Capture warning / notice as exception
set_error_handler('mp_exceptions_error_handler');
function mp_exceptions_error_handler($severity, $message, $filename, $lineno) {
    if (error_reporting() == 0) {
        return;
    }
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $filename, $lineno);
    }
}

require __DIR__ . '/../app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);

$obj = $bootstrap->getObjectManager();

$state = $obj->get('Magento\Framework\App\State');
$state->setAreaCode('adminhtml');


/**************************************************************************************************/
// UTILITY FUNCTIONS - START
/**************************************************************************************************/
function _mpLog($data, $includeSep = false)
{
    $fileName = BP . '/var/log/m2-magepsycho-import-stocks.log';
    if ($includeSep) {
        $separator = str_repeat('=', 70);
        file_put_contents($fileName, $separator . '<br />' . PHP_EOL,  FILE_APPEND | LOCK_EX);
    }
    file_put_contents($fileName, $data . '<br />' .PHP_EOL,  FILE_APPEND | LOCK_EX);
}

function mpLogAndPrint($message, $separator = false)
{
    _mpLog($message, $separator);
    if (is_array($message) || is_object($message)) {
        print_r($message);
    } else {
        echo $message . '<br />' . PHP_EOL;
    }

    if ($separator) {
        echo str_repeat('=', 70) . '<br />' . PHP_EOL;
    }
}

function getIndex($field)
{
    global $headers;
    $index = array_search($field, $headers);
    if ( !strlen($index)) {
        $index = -1;
    }
    return $index;
}

function readCsvRows($csvFile)
{
    $rows = [];
    $fileHandle = fopen($csvFile, 'r');
    while(($row = fgetcsv($fileHandle, 0, ',', '"', '"')) !== false) {
        $rows[] = $row;
    }
    fclose($fileHandle);
    return $rows;
}

function _getResource()
{
    global $obj;
    return $obj->get('Magento\Framework\App\ResourceConnection');
}

function _getConnection()
{
    return _getResource()->getConnection();
}

function _getTableName($tableName)
{
    return _getResource()->getTableName($tableName);
}

function _getIdFromSku($sku)
{
    $connection = _getConnection();
    $sql        = "SELECT entity_id FROM " . _getTableName('catalog_product_entity') . " WHERE sku = ?";
    return $connection->fetchOne(
        $sql,
        [
            $sku
        ]
    );
}

function checkIfSkuExists($sku)
{
    $connection = _getConnection();
    $sql        = "SELECT COUNT(entity_id) AS count_no FROM " . _getTableName('catalog_product_entity') . " WHERE sku = ?";
    return $connection->fetchOne($sql, [$sku]);
}

/**
 * Updates the stock/qty
 * Note: It doesn't take care for multi-source/website based inventory
 * For proper results, stock indexing is required
 *
 * @param $entityId
 * @param $qty
 * @return int
 */
function updateStocks($entityId, $qty)
{
    $connection =_getConnection();
    $qty = (int) $qty;
    $isInStock = $qty > 0 ? 1 : 0;
    $stockStatus = $qty > 0 ? 1 : 0;

    $sql = "UPDATE " . _getTableName('cataloginventory_stock_item') . " SET qty = :qty, is_in_stock = :is_in_stock WHERE product_id = :product_id;";
    $query = $connection->query(
        $sql, [
            'qty' => $qty,
            'is_in_stock' => $isInStock,
            'product_id' => $entityId,
        ]
    );
    return $query->rowCount();
}

/**************************************************************************************************/
// UTILITY FUNCTIONS - END
/**************************************************************************************************/

try {
    #EDIT - The path to import CSV file (Relative to Magento2 Root)
    $csvFile        = 'var/import/stock_sample.csv';
    $csvData        = readCsvRows(BP . '/' . $csvFile);
    $headers        = array_shift($csvData);

    $count = 0;
    foreach($csvData as $_data) {
        $count++;
        $sku   = $_data[getIndex('sku')];
        $qty = $_data[getIndex('qty')];

        if (! ($entityId = _getIdFromSku($sku))) {
            $message =  $count . '. FAILURE:: Product with SKU (' . $sku . ') doesn\'t exist.';
            mpLogAndPrint($message);
            continue;
        }

        try {
            $updatedCount = updateStocks($entityId, $qty);
            $message = $count . '. SUCCESS:: Updated SKU (' . $sku . ') with qty (' . $qty . '), UpdatedCount::' . (int)$updatedCount;
            mpLogAndPrint($message);
        } catch(Exception $e) {
            $message =  $count . '. ERROR:: While updating  SKU (' . $sku . ') with qty (' . $qty . ') => ' . $e->getMessage();
            mpLogAndPrint($message);
        }
    }
} catch (Exception $e) {
    mpLogAndPrint('EXCEPTION::' . $e->getTraceAsString());
}
