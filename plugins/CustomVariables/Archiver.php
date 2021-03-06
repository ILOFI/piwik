<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\CustomVariables;

use Piwik\Common;
use Piwik\Config;
use Piwik\DataAccess\LogAggregator;
use Piwik\DataArray;
use Piwik\Metrics;
use Piwik\Tracker\GoalManager;
use Piwik\Tracker;

require_once PIWIK_INCLUDE_PATH . '/libs/PiwikTracker/PiwikTracker.php';

class Archiver extends \Piwik\Plugin\Archiver
{
    const LABEL_CUSTOM_VALUE_NOT_DEFINED = "Value not defined";
    const CUSTOM_VARIABLE_RECORD_NAME = 'CustomVariables_valueByName';

    // Ecommerce reports use custom variables.
    // We specifically set the limits high to get accurate Ecommerce reports
    const MAX_ROWS_WHEN_ECOMMERCE = 50000;

    /**
     * @var DataArray
     */
    protected $dataArray;
    protected $maximumRowsInDataTableLevelZero;
    protected $maximumRowsInSubDataTable;
    protected $newEmptyRow;

    function __construct($processor)
    {
        parent::__construct($processor);

        if ($processor->getParams()->getSite()->isEcommerceEnabled()) {
            $this->maximumRowsInDataTableLevelZero = self::MAX_ROWS_WHEN_ECOMMERCE;
            $this->maximumRowsInSubDataTable = self::MAX_ROWS_WHEN_ECOMMERCE;
        } else {
            $this->maximumRowsInDataTableLevelZero = Config::getInstance()->General['datatable_archiving_maximum_rows_custom_variables'];
            $this->maximumRowsInSubDataTable = Config::getInstance()->General['datatable_archiving_maximum_rows_subtable_custom_variables'];
        }
    }

    public function aggregateMultipleReports()
    {
        $this->getProcessor()->aggregateDataTableRecords(
            self::CUSTOM_VARIABLE_RECORD_NAME, $this->maximumRowsInDataTableLevelZero, $this->maximumRowsInSubDataTable,
            $columnToSort = Metrics::INDEX_NB_VISITS);
    }

    public function aggregateDayReport()
    {
        $this->dataArray = new DataArray();

        $maxCustomVariables = CustomVariables::getMaxCustomVariables();
        for ($i = 1; $i <= $maxCustomVariables; $i++) {
            $this->aggregateCustomVariable($i);
        }

        $this->removeVisitsMetricsFromActionsAggregate();
        $this->dataArray->enrichMetricsWithConversions();
        $table = $this->dataArray->asDataTable();
        $blob = $table->getSerialized(
            $this->maximumRowsInDataTableLevelZero, $this->maximumRowsInSubDataTable,
            $columnToSort = Metrics::INDEX_NB_VISITS
        );

        $this->getProcessor()->insertBlobRecord(self::CUSTOM_VARIABLE_RECORD_NAME, $blob);
    }

    protected function aggregateCustomVariable($slot)
    {
        $keyField = "custom_var_k" . $slot;
        $valueField = "custom_var_v" . $slot;
        $where = "%s.$keyField != ''";
        $dimensions = array($keyField, $valueField);

        $query = $this->getLogAggregator()->queryVisitsByDimension($dimensions, $where);
        $this->aggregateFromVisits($query, $keyField, $valueField);

        // IF we query Custom Variables scope "page" either: Product SKU, Product Name,
        // then we also query the "Product page view" price which was possibly recorded.
        $additionalSelects = false;

        if (in_array($slot, array(\PiwikTracker::CVAR_INDEX_ECOMMERCE_ITEM_SKU, \PiwikTracker::CVAR_INDEX_ECOMMERCE_ITEM_NAME, \PiwikTracker::CVAR_INDEX_ECOMMERCE_ITEM_CATEGORY))) {
            $additionalSelects = array($this->getSelectAveragePrice());
        }
        $query = $this->getLogAggregator()->queryActionsByDimension($dimensions, $where, $additionalSelects);
        $this->aggregateFromActions($query, $keyField, $valueField);

        $query = $this->getLogAggregator()->queryConversionsByDimension($dimensions, $where);
        $this->aggregateFromConversions($query, $keyField, $valueField);
    }

    protected function getSelectAveragePrice()
    {
        $field = "custom_var_v" . \PiwikTracker::CVAR_INDEX_ECOMMERCE_ITEM_PRICE;
        return LogAggregator::getSqlRevenue("AVG(log_link_visit_action." . $field . ")") . " as `" . Metrics::INDEX_ECOMMERCE_ITEM_PRICE_VIEWED . "`";
    }

    protected function aggregateFromVisits($query, $keyField, $valueField)
    {
        while ($row = $query->fetch()) {
            $key = $row[$keyField];
            $value = $this->cleanCustomVarValue($row[$valueField]);

            $this->dataArray->sumMetricsVisits($key, $row);
            $this->dataArray->sumMetricsVisitsPivot($key, $value, $row);
        }
    }

    protected function cleanCustomVarValue($value)
    {
        if (strlen($value)) {
            return $value;
        }
        return self::LABEL_CUSTOM_VALUE_NOT_DEFINED;
    }

    protected function aggregateFromActions($query, $keyField, $valueField)
    {
        while ($row = $query->fetch()) {
            $key = $row[$keyField];
            $value = $this->cleanCustomVarValue($row[$valueField]);

            $alreadyAggregated = $this->aggregateEcommerceCategories($key, $value, $row);
            if (!$alreadyAggregated) {
                $this->aggregateActionByKeyAndValue($key, $value, $row);
                $this->dataArray->sumMetricsActions($key, $row);
            }
        }
    }

    /**
     * @param string $key
     * @param string $value
     * @param $row
     * @return bool True if the $row metrics were already added to the ->metrics
     */
    protected function aggregateEcommerceCategories($key, $value, $row)
    {
        $ecommerceCategoriesAggregated = false;
        if ($key == '_pkc'
            && $value[0] == '[' && $value[1] == '"'
        ) {
            // In case categories were truncated, try closing the array
            if (substr($value, -2) != '"]') {
                $value .= '"]';
            }
            $decoded = json_decode($value);
            if (is_array($decoded)) {
                $count = 0;
                foreach ($decoded as $category) {
                    if (empty($category)
                        || $count >= GoalManager::MAXIMUM_PRODUCT_CATEGORIES
                    ) {
                        continue;
                    }
                    $this->aggregateActionByKeyAndValue($key, $category, $row);
                    $ecommerceCategoriesAggregated = true;
                    $count++;
                }
            }
        }
        return $ecommerceCategoriesAggregated;
    }

    protected function aggregateActionByKeyAndValue($key, $value, $row)
    {
        $this->dataArray->sumMetricsActionsPivot($key, $value, $row);

        if ($this->isReservedKey($key)) {
            // Price tracking on Ecommerce product/category pages:
            // the average is returned from the SQL query so the price is not "summed" like other metrics
            $index = Metrics::INDEX_ECOMMERCE_ITEM_PRICE_VIEWED;
            if (!empty($row[$index])) {
                $this->dataArray->setRowColumnPivot($key, $value, $index, (float)$row[$index]);
            }
        }
    }

    protected static function isReservedKey($key)
    {
        return in_array($key, API::getReservedCustomVariableKeys());
    }

    protected function aggregateFromConversions($query, $keyField, $valueField)
    {
        if ($query === false) {
            return;
        }
        while ($row = $query->fetch()) {
            $key = $row[$keyField];
            $value = $this->cleanCustomVarValue($row[$valueField]);
            $this->dataArray->sumMetricsGoals($key, $row);
            $this->dataArray->sumMetricsGoalsPivot($key, $value, $row);
        }
    }

    protected function removeVisitsMetricsFromActionsAggregate()
    {
        $dataArray = & $this->dataArray->getDataArray();
        foreach ($dataArray as $key => &$row) {
            if (!self::isReservedKey($key)
                && DataArray::isRowActions($row)
            ) {
                unset($row[Metrics::INDEX_NB_UNIQ_VISITORS]);
                unset($row[Metrics::INDEX_NB_VISITS]);
                unset($row[Metrics::INDEX_NB_USERS]);
            }
        }
    }

}
