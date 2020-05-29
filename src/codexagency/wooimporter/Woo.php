<?php

namespace CodexAgency\WooImporter;


use splitbrain\phpcli\CLI;
use splitbrain\phpcli\Options;
use splitbrain\phpcli\Colors;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SplFileInfo;
use splitbrain\phpcli\TableFormatter;
use CodexAgency\WooImporter\CSV;
use WC_Data_Exception;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Widget_Product_Categories;
use WP;


class Woo extends CLI
{

    /**
     * @var string
     */
    protected $csvPath;
    protected $logger;

    protected $WP;

    protected $CSV;

    /**
     * @var $productVariableInterface WC_Product_Variable
     */
    protected $productVariableInterface;

    /**
     * @var $productAttrInterface WC_Product_Attribute
     */
    protected $productAttrInterface;

    /**
     * @var $productVariationsInterface WC_Product_Variation
     */
    protected $productVariationsInterface;

    /**
     * @var $categoryInterface WC_Widget_Product_Categories;
     */
    protected $categoryInterface;


    public function __construct()
    {
        $this->CSV = new \ParseCsv\Csv();
        $this->logger = new NullLogger();
        $this->productVariableInterface = new WC_Product_Variable();
        $this->productAttrInterface = new WC_Product_Attribute();
        $this->productVariationsInterface = new WC_Product_Variation();
        parent::__construct();
    }

    public function setCsvPath(string $path): Woo
    {
        $this->csvPath = $path;
        return $this;
    }

    protected function setup(Options $options): void
    {
        $options->setHelp('Import csv in woocommerce');
        $options->registerOption('version', 'print version', 'v');
        $options->registerOption('list', 'View cvs export dir', 'l');
        $options->registerOption('import', 'Import al files, if if you specify filename it imports the specified file', 'i', false);
    }

    protected function fileList(): void
    {
        $files = scandir($this->csvPath);
        $csvFiles = [];
        $i = 1;
        foreach ($files as $key => $file) {
            $info = new SplFileInfo($this->csvPath . $file);
            $lastModified = date('Y/m/d', filemtime($this->csvPath . $file));
            if ($info->getExtension() === 'csv') {
                $csvFiles[$i][] = $file;
                $csvFiles[$i][] = $lastModified;
                $i++;
            }
        }

        echo PHP_EOL . PHP_EOL;


        $table = new TableFormatter($this->colors);
        $headers = [
            'size' => ['80%', '20%'],
            'name' => ['File Name', 'Last Modified'],
            'color' => [Colors::C_BLUE, Colors::C_LIGHTGREEN]
        ];
        $table->setBorder(' | ');


        echo $table->format(
            $headers['size'],
            $headers['name'],
            $headers['color']
        );

        echo str_pad('', $table->getMaxWidth(), '-') . "\n";

        foreach ($csvFiles as $key => $file) {
            echo $table->format(
                $headers['size'],
                $file,
                $headers['color']
            );
        }

        echo PHP_EOL;


    }

    /**
     * @param null $filename
     * @throws WC_Data_Exception
     */
    protected function import($filename = null): void
    {
        $files = [];
        if ($filename && !file_exists($this->csvPath . $filename)) {
            $this->warning('This file not exist');
            return;
        }

        if ($filename && file_exists($this->csvPath . $filename)) {
            $files[] = $filename;
        } else {
            $tmpFiles = scandir($this->csvPath);
            foreach ($tmpFiles as $key => $file) {
                $info = new SplFileInfo($this->csvPath . $file);
                if ($info->getExtension() === 'csv') {
                    $files[] = $file;
                }
            }
        }

        $fileProducts = [];

        foreach ($files as $key => $name) {
            $this->CSV->delimiter = ';';
            if ($this->CSV->parse($this->csvPath . $name)) {
                $fileProducts[] = $this->CSV->data;
            }
        }

        $products = [];
        foreach ($fileProducts as $file) {
            foreach ($file as $product) {
                $products[] = $product;
            }
        }


        foreach ($products as $product) {
            $iterateVariableProduct = [];
            if (!$variableProductID = wc_get_product_id_by_sku($product['CODICE'])) {
                $this->createVariable($this->getsVariationInArrayByCode($products, $product['CODICE']));
            } else {
                $this->updateVariable($this->getsVariationInArrayByCode($products, $product['CODICE']), $variableProductID);
            }
        }

        foreach ($files as $filename) {
            $this->deleteFile($filename);
        }
    }

    /**
     * @param string $filename es.: file.csv
     * @return void
     */
    protected function deleteFile(string $filename)
    {
        $tmpPath = $this->csvPath . $filename;
        if (file_exists($tmpPath)) {
            unlink($tmpPath);
        }
    }

    /**
     * @param WC_Product_Variable $variableProduct
     * @param string $categoryName
     * @return WC_Product_Variable
     */
    protected function setVariableProductCategory(WC_Product_Variable $variableProduct, string $categoryName): WC_Product_Variable
    {
        if (!$catID = $this->getCategoryIdByName($categoryName)) {
            $catID = $this->createProductCategory($categoryName);
        }
        $variableProduct->set_category_ids([$catID]);
        return $variableProduct;
    }

    /**
     * Dato in ingresso il nome della categoria ritorna l'id
     * @param string $categoryName
     * @return int
     */
    protected function getCategoryIdByName(string $categoryName): ?int
    {
        return get_terms([
            'taxonomy' => 'product_cat',
            'name' => $categoryName,
            'hide_empty' => false
        ])[0]->term_id;
    }

    /**
     * Crea una categoria su WooCommerce
     * @param string $categoryName
     * @return int
     */
    protected function createProductCategory(string $categoryName): ?int
    {
        return wp_insert_term($categoryName, 'product_cat')['term_id'];
    }

    /**
     * Dato in ingresso tutti i prodotti e il codice del macro prodotto
     * restituisce un'array contente solo i prodotti figlio
     * @param array $products
     * @param int $code
     * @return array
     */
    protected function getsVariationInArrayByCode(array $products, int $code): array
    {
        $variations = [];
        foreach ($products as $product) {
            if ($code === (int) $product['CODICE']) {
                $variations[] = $product;
            }
        }
        return $variations;
    }

    /**
     * Crea un prodotto variabile e le variazioni figlie,
     * passare in ingresso un'array contenente tutte le variazioni
     * @param array $productsData
     * @return int
     * @throws WC_Data_Exception
     */
    protected function createVariable(array $productsData): int
    {
        $attrSizeValues = array_unique(array_column($productsData, 'TAGLIA'));
        $attrColorValues = array_unique(array_column($productsData, 'COLORE'));

        $productData = $productsData[0];
        /**
         * @var WC_Product_Variable $wcProduct
         */
        $wcProduct = new $this->productVariableInterface;
        $wcProduct->set_sku($productData['CODICE']);
        $wcProduct->set_name($productData['NOME']);

        /**
         * @var WC_Product_Attribute $attrSize
         */
        $attrSize = new $this->productAttrInterface;
        $attrSize->set_name('Taglia');
        $attrSize->set_visible(true);
        $attrSize->set_variation(true);
        $attrSize->set_options($attrSizeValues);

        /**
         * @var $attrColor WC_Product_Attribute;
         */
        $attrColor = new $this->productAttrInterface;
        $attrColor->set_name('Colore');
        $attrColor->set_visible(true);
        $attrColor->set_variation(true);
        $attrColor->set_options($attrColorValues);


        $wcProduct->set_attributes([$attrColor, $attrSize]);
        $wcProduct = $this->setVariableProductCategory($wcProduct, $productData['REPARTO']);
        //$wcProduct->set_manage_stock(true);
        $wcProduct->set_stock_status();
        $variableID = $wcProduct->save();

        foreach ($productsData as $variation) {
            $this->createSimple($variation, $variableID);
        }
        return $variableID;
    }

    /**
     * Crea una variazione prodotto
     * @param array $productData
     * @param int $parent_id
     * @return int
     * @throws WC_Data_Exception
     */
    protected function createSimple(array $productData, int $parent_id): int
    {
        /**
         * @var $wcVariationsProduct WC_Product_Variation
         */
        $wcVariationsProduct = new $this->productVariationsInterface;
        $wcVariationsProduct->set_sku($productData['CODICE'] . '-' . $productData['CODICE_VARIANTE']);
        $wcVariationsProduct->set_parent_id($parent_id);

        $wcVariationsProduct->set_attributes(
            [
                'colore' => $productData['COLORE'],
                'taglia' => $productData['TAGLIA'],
            ]
        );


        /**
         * @var $attrSize WC_Product_Attribute;
         */
        $attrSize = new $this->productAttrInterface($parent_id);
        $attrSize->set_name('Taglia');
        $attrSize->set_visible(true);
        $attrSize->set_variation(true);

        /**
         * @var $attrColor WC_Product_Attribute;
         */
        $attrColor = new $this->productAttrInterface;
        $attrColor->set_name('Colore');
        $attrColor->set_visible(true);
        $attrColor->set_variation(true);

        $wcVariationsProduct->set_stock_quantity((float) $productData['QUANTITA']);
        $wcVariationsProduct->set_manage_stock(true);


        //$wcVariationsProduct->set_price((float) $productData['PREZZO_VENDITA']);
        $wcVariationsProduct->set_regular_price((float) $productData['PREZZO_VENDITA']);
        return $wcVariationsProduct->save();
    }

    /**
     * In ingresso tutte le variazioni prodotto, aggiorna qty e prezzi
     * @param array $productsData
     * @param int $productID
     */
    protected function updateVariable(array $productsData, int $productID): void
    {
        /**
         * @var $variableProduct WC_Product_Variable
         */
        $variableProduct = new $this->productVariableInterface($productID);
        $variableProduct->set_stock_status('instock');
        $variableProduct->save();

        foreach ($productsData as $product) {
            if ($variationID = wc_get_product_id_by_sku($product['CODIE'] . '-' . $product['CODICE_VARIANTE'])) {
                $this->updateSimple($product, $variationID);
            }
        }
    }

    /**
     * In ingresso un'array del prodotto come da csv e l'id del prodoto nel db
     * aggiorna qty e prezzi
     * @param array $productData
     * @param int $productID
     * @return int
     */
    protected function updateSimple(array $productData, int $productID): int
    {
        /**
         * @var $product WC_Product_Variation
         */
        //$productStock = wc_update_product_stock($productID);
        $product = new $this->productVariationsInterface($productID);
        //$product = new WC_Product_Variation($productID);
        $product->set_regular_price((float) $productData['PREZZO_VENDITA']);

        if ((float) $productData['QUANTITA'] > 0) {
            $product->set_stock_quantity((float) $productData['QUANTITA']);
            $product->set_manage_stock(true);
            $product->set_stock_status('instock');
        } else {
            $product->set_stock_quantity(0);
            $product->set_manage_stock(false);
            $product->set_stock_status('outofstock');
        }

        return $product->save();
    }


    protected function main(Options $options): void
    {
        if ($options->getOpt('version')) {
            $this->info('0.0.1');
        } else if ($options->getOpt('list')) {
            $this->fileList();
        } else if ($options->getOpt('import')) {
            if ($options->getArgs()) {
                $this->import($options->getArgs()[0]);
            } else {
                $this->import();
            }
        } else {
            echo $options->help();
        }
    }

}
