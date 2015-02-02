<?php

namespace app\backend\controllers;

use app\models\Config;
use app\models\Product;
use app\models\Property;
use app\properties\PropertyValue;
use Yii;
use app\models\Category;
use yii\base\Controller;
use yii\base\InvalidParamException;

class YmlController extends Controller
{
    public function actionIndex()
    {
        header('content-type: application/xml');

        $document = $this->buidDocument();
        echo $document->saveXML();

        return;
    }

    public function actionSettings()
    {
        if (!empty($_POST['yml'])) {
            $yml = $_POST['yml'];


            $config = Config::find()->where(['key' => 'show_all_properties'])->one();
            if ($config) {
                if (isset($yml['show_all_properties'])) {
                    $config->value = "1";
                    unset($yml['show_all_properties']);
                } else {
                    $config->value = "0";
                }

                $config->update();
            }

            foreach ($yml as $key => $value) {
                $config = Config::find()->where(['key' => $key])->one();
                if ($config) {
                    $config->value = $value;
                    $config->update();
                }
            }
        }

        return $this->render('settings',
            [
                'main_currency' => Config::getValue("yml.main_currency"),
                'show_all_properties' => Config::getValue("yml.show_all_properties"),
                'default_offer_type' => Config::getValue("yml.default_offer_type"),
                'local_delivery_cost' => Config::getValue("yml.local_delivery_cost")
            ]
        );
    }

    private function buidDocument()
    {
        $document = new \DOMDocument("1.0", "windows-1251");
        $ymlCatalog = $this->buildYmlCatalog($document);

        $document->appendChild($ymlCatalog);

        return $document;
    }

    /**
     * @param \DOMDocument $doc
     * @return mixed
     */
    private function buildYmlCatalog($doc)
    {
        $yml_catalog = $doc->createElement('yml_catalog');
        $yml_catalog->setAttribute('date', date("Y-m-d h:m", time()));

        $yml_catalog->appendChild($this->buildShop($doc));;
        return $yml_catalog;
    }

    /**
     * @param \DOMDocument $doc
     * @return mixed
     */
    private function buildShop($doc)
    {
        $shop = $doc->createElement('shop');

        // <name>
        $nameConf = Config::getValue('shop.name');
        if (null === $nameConf) {
            throw new InvalidParamException(Yii::t('app', 'Не задано название магазина'));
        }
        $name = $doc->createElement('name', $nameConf);
        $shop->appendChild($name);

        // <company>
        $companyConf = Config::getValue('shop.company');
        if (null === $companyConf) {
            throw new InvalidParamException(Yii::t('app', 'Не задано название компании'));
        }
        $company = $doc->createElement('company', $companyConf);
        $shop->appendChild($company);

        // <url>
        $urlConf = Config::getValue('core.serverName');
        if (null === $nameConf) {
            throw new InvalidParamException(Yii::t('app', 'Не задан URL магазина'));
        }
        $url = $doc->createElement('url', $urlConf);
        $shop->appendChild($url);

        // local delivery cost
        $local_delivery_cost = Config::getValue('yml.local_delivery_cost');
        if (!empty($local_delivery_cost)) {
            $ldc = $doc->createElement('local_delivery_cost', $local_delivery_cost);
            $shop->appendChild($ldc);
        }

        $shop->appendChild($this->buildCurrencies($doc));
        $shop->appendChild($this->buildCategories($doc));
        $shop->appendChild($this->buildOffers($doc));

        return $shop;
    }

    /**
     * @param \DOMDocument $doc
     * @return mixed
     */
    private function buildCurrencies($doc)
    {
        // <currencies>
        $currenciesRoot = $doc->createElement('currencies');

        $exchangeRate = [
            // 'id' => 'rate'
            "RUR" => "CB",
            "USD" => "CB",
            "EUR" => "CB",
            "UAH" => "CB",
            "KZT" => "CB",
        ];

        // set main currency
        $mainCurrency = Config::getValue('yml.main_currency');
        if (!empty($mainCurrency)) {
            unset($exchangeRate[$mainCurrency]);
            $exchangeRate[$mainCurrency] = '1';
        }

        foreach ($exchangeRate as $id => $rate) {
            $currency = $doc->createElement('currency');

            $currency->setAttribute('id', $id);
            $currency->setAttribute('rate', $rate);

            $currenciesRoot->appendChild($currency);
        }

        return $currenciesRoot;
    }

    /**
     * @param \DOMDocument $doc
     * @return \DOMElement
     */
    private function buildCategories($doc)
    {
        $categories = $doc->createElement('categories');

        $allCat = Category::find()->all();

        /** @var Category $cat */
        foreach ($allCat as $cat) {
            $catElement = $doc->createElement('category', $cat->name);
            $catElement->setAttribute('id', $cat->id);
            if ($cat->parent_id > 0) {
                $catElement->setAttribute('parentId', $cat->parent_id);
            }

            $categories->appendChild($catElement);
        }

        return $categories;
    }

    /**
     * @param \DOMDocument $doc
     * @return \DOMElement
     */
    private function buildOffers($doc)
    {
        $offers = $doc->createElement('offers');
        $products = Product::find()->all();
        /** @var Product $product */
        foreach ($products as $product) {
            $offer = $doc->createElement('offer');
            $offer->setAttribute('id', $product->id);

            // общие для всех типов поля
            $url = $doc->createElement('url', '123'); // TODO запилить url
            $price = $doc->createElement('price', $product->price);
            $currencyId = $doc->createElement('currencyId', 'RUR');
            $categoryId = $doc->createElement('categoryId', $product->main_category_id);

            $offer->appendChild($url);
            $offer->appendChild($price);
            $offer->appendChild($currencyId);
            $offer->appendChild($categoryId);


            // не общие
            $offerType = $product->getPropertyValuesByKey('offerType');
            if (!empty($offerType)) {
                $offerType = ($offerType === 'simplified' ? '' : $offerType);
            } else {
                $offerType = Config::getValue('yml.default_offer_type');
                if (!empty($offerType)) {
                    $offerType = ($offerType === 'simplified' ? '' : $offerType);
                }
            }

            if (!empty($offerType)) {
                $offer->setAttribute('type', $offerType);
            }


            $available = "true";
            /** @var PropertyValue $available */
            $pAvailable = $product->getPropertyValuesByKey('available');
            if (is_object($pAvailable)) {
                $available = true && (int) $pAvailable->toValue() ? "true" : "false";
                $offer->setAttribute('available', $available);
            }


            $fields = [];
            switch($offerType) {
                case 'vendor.model':
                    $fields = [
                        'delivery',
                        'local_delivery_cost',
                        'typePrefix',
                        'vendor',
                        'available',
                        'sales_notes',
                        'vendorCode',
                        'model',
                        'description',
                        'manufacturer_warranty',
                        'country_of_origin',
                        'downloadable',
                        'adult',
                    ];

                    // TODO в vendor.model можно добавлять дополнительные параметры <param name="Вес" unit="кг">2.73</param> ...
                    $aGroups = $product->getPropertyGroups();

                    foreach ($aGroups as $pGroup) {
                        /** @var PropertyValue $propValue */
                        foreach ($pGroup as $propValue) {
                            $property = Property::findById($propValue->property_id);
                            if (is_object($property)) {
                                if ($property->as_yml_field) {
                                    $param = $doc->createElement('param', $propValue->toValue());
                                    $param->setAttribute('name', $property->name);

                                    $offer->appendChild($param);
                                }
                            }
                        }
                    }
                    break;
                case 'book':
                    $fields = [
                        'delivery',
                        'local_delivery_cost',
                        'description',
                        'author',
                        'name',
                        'publisher',
                        'series',
                        'year',
                        'ISBN',
                        'volume',
                        'binding',
                        'part',
                        'language',
                        'page_extent',
                        'downloadable',
                        'table_of_contents',
                    ];
                    break;
                case 'audiobook':
                    $field = [
                        'description',
                        'author',
                        'name',
                        'publisher',
                        'year',
                        'ISBN',
                        'language',
                        'binding',
                        'downloadable',
                        'performed_by',
                        'performance_type',
                        'storage',
                        'format',
                        'recording_length',
                        'table_of_contents',
                    ];
                    break;
                case 'artist.title':
                    $field = [
                        'artist',
                        'delivery',
                        'description',
                        'year',
                        'title',
                        'media',
                        'starring',
                        'director',
                        'originalName',
                        'country',
                    ];
                    break;
                case 'tour':
                    $fields = [
                        'delivery',
                        'local_delivery_cost',
                        'description',
                        'name',
                        'country',
                        'worldRegion',
                        'region',
                        'days',
                        'dataTour',
                        'hotel_stars',
                        'room',
                        'meal',
                        'included',
                        'transport',
                    ];
                    break;
                case 'event-ticket':
                    $fields = [
                        'hall plan',
                        'delivery',
                        'local_delivery_cost',
                        'description',
                        'name',
                        'place',
                        'hall',
                        'hall_part',
                        'date',
                        'is_premiere',
                        'is_kids',
                    ];
                    break;
                default: // 'simplified'
                    $fields = [
                        'delivery',
                        'local_delivery_cost',
                        'vendor',
                        'vendorCode',
                        'description',
                        'country_of_origin',
                        'name',
                        'adult',
                    ];
            }

            foreach ($fields as $field) {
                if ($val = $product->getPropertyValuesByKey($field)) {
                    $$field = $doc->createElement($field, $val);
                    $offer->appendChild($$field);
                }
            }

            $offers->appendChild($offer);
        }

        return $offers;
    }
}
