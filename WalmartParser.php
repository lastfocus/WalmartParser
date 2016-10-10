<?php

/**
 * Created by PhpStorm.
 * User: focus
 * Date: 11.10.2016
 * Time: 21:58
 */

/**
 * Class WalmartParser
 * Тестовая задача для SKU GRID
 * Реализация парсера Walmart магазина
 * @package Parsers
 */

class WalmartParser
{
    /**
     * Получение информации о товаре
     * <b>Исключения, коды ошибок:</b>
     * 1001 - пустой url
     * 1002 - информация о товаре не найдена
     * 1003 - информация о вариантах товара не найдена
     * 1004 - информация о наличии товара не найдена
     * 1005 - информация о цене товара не найдена
     * 1006 - информация о доставке товара не найдена
     * @param $url
     * @param $zipcode
     * @return array
     * @throws \Exception
     */

    public function getItemInfo($url,$zipcode=10001) {
        if (is_null($url)) {
            throw new \Exception('Empty url', 1001);
        }
        $data = [];
        $result = [];

        $curl = curl_init();
        // Опции для curl
        curl_setopt($curl, CURLOPT_URL, $url); // target
        curl_setopt($curl, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']); // provide a user-agent
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); // follow any redirects
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // return the result

        //получение исходного кода
        $str = curl_exec($curl);

        //регулярное выражение на информацию о товаре
        preg_match('/define\("product\/data",(.*)\);(.*)define\("athena/s',$str,$data);

        //преобразуем в объект, убирая перед этим все html тэги (на случай, когда описание товара использует их
        $data = json_decode(strip_tags($data[1]));

        if (!property_exists($data,'variantInformation'))
            throw new \Exception('variantInformation not found', 1002);
        if (!property_exists($data->variantInformation,'variantTypes'))
            throw new \Exception('variantTypes information not found', 1003);

        //получаем варианты товара
        $groups = $data->variantInformation->variantTypes;
        foreach ($groups as $group) {
            $result['variants'][$group->name]= [];
            foreach ($group->variants as $variant) {
                $result['variants'][$group->name][] = $variant->name;
            }
        }
        $items = $data->variantInformation->variantProducts;
        $count = 0;
        //для каждого варианта товара ищем информацию о размере,цвете
        foreach ($items as $item) {
            $result['items'][$count] = [];
            $result['items'][$count]['id'] = $item->buyingOptions->usItemId;
            if (property_exists($item->variants,'size')) {
                $result['items'][$count]['size'] = $item->variants->size->name;
            }
            if (property_exists($item->variants,'actual_color')) {
                $result['items'][$count]['actual_color'] = $item->variants->actual_color->name;
            }

            //запрашиваем информацию о цене, доставке и наличии варианта товара
            curl_setopt($curl, CURLOPT_URL, 'https://www.walmart.com/product/api/'.$item->buyingOptions->usItemId.'?location='.$zipcode.'&selected=true'); // target
            $str = curl_exec($curl);
            $data = json_decode($str);
            if (!property_exists($data->buyingOptions,'available'))
                throw new \Exception("Cant load stock status (usitemid=".$item->buyingOptions->usItemId.")", 1004);
            if (!property_exists($data->buyingOptions,'price'))
                throw new \Exception("Cant load price  info (usitemid=".$item->buyingOptions->usItemId.")", 1005);
            if (!property_exists($data->buyingOptions,'shippable'))
                throw new \Exception("Cant load shipping info (usitemid=".$item->buyingOptions->usItemId.")", 1006);

            //наличие товара
            $result['items'][$count]['status']  = $data->buyingOptions->available;
            //цена товара
            $result['items'][$count]['price']  =$data->buyingOptions->price->currencyUnitSymbol.$data->buyingOptions->price->currencyAmount;
            if ($data->buyingOptions->shippable == true) {

                $result['items'][$count]['shipping']  = [];

                foreach ($data->buyingOptions->shippingOptions as $option) {
                    $result['items'][$count]['shipping'][] = [
                        //название метода доставки и цена
                        'name' => $option->displayShippingMethod,
                        'shippingPrice' => $option->shippingPrice->currencyUnitSymbol.$option->shippingPrice->currencyAmount,
                    ];
                }
            }
            else
                $result['items'][$count]['shipping'] = false; //доставка недоступна

            $count++;
        }
        curl_close($curl);

        //общее количество вариантов товара
        $result['items_count'] = $count;

        return $result;
    }
}