<?php

declare(strict_types=1);

namespace Magento\QuoteGraphQl\Plugin;

use Magento\Framework\Api\ExtensibleDataObjectConverter;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Model\Cart\ShippingMethodConverter;
use Magento\Quote\Model\Quote\TotalsCollector;
use ShipperHQ\Shipper\Model\Quote\AddressDetailFactory;
use ShipperHQ\Shipper\Helper\Data;
use Magento\Sales\Api\Data\OrderInterface;
use ShipperHQ\Shipper\Model\Order\DetailFactory as OrderDetailFactory;
use Magento\SalesGraphQl\Model\Formatter\Order;
use Magento\QuoteGraphQl\Model\Resolver\ShippingAddress\AvailableShippingMethods;

class AddOriginAddress
{
    public const SHIPPERHQ_CODE = "shq";

    /**
     * @var AddressDetailFactory
     */
    public $shipperHqAddressDetail;

    /**
     * @var Data
     */
    public $shipperDataHelper;
    /**
     * @var OrderDetailFactory
     */
    public $shipperHqOrderDetail;

    /**
     * @param AddressDetailFactory $shipperHqAddressDetail
     * @param OrderDetailFactory $shipperHqOrderDetail
     * @param Data $shipperDataHelper
     */
    public function __construct(
        AddressDetailFactory $shipperHqAddressDetail,
        OrderDetailFactory $shipperHqOrderDetail,
        Data $shipperDataHelper
    ) {
        $this->shipperHqAddressDetail = $shipperHqAddressDetail;
        $this->shipperHqOrderDetail   = $shipperHqOrderDetail;
        $this->shipperDataHelper      = $shipperDataHelper;
    }

    /**
     * @inheritdoc
     */
    public function afterResolve(
        AvailableShippingMethods $subject,
        $result,
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {

        try {
            $hqAddressModel = $this->shipperHqAddressDetail->create();
            $address = null;

            if (array_key_exists('model', (array) $value)) {
                /** @phpstan-ignore-next-line */
                $address = clone $value['model'];
            }

            $carrierData = $hqAddressModel->loadByAddress($address->getQuote()->getShippingAddress()->getId())->getFirstItem();

            $carrierData = $this->shipperDataHelper->decodeShippingDetails(
                $carrierData->getCarrierGroupDetail()
            );

            $carrierData = end($carrierData);

            if (!empty($result)) {
                foreach ($result as $key => $shipper) {
                    if (array_key_exists("carrier_code", $shipper) && str_contains($shipper["carrier_code"], self::SHIPPERHQ_CODE) && is_array($carrierData) && array_key_exists("originAddress", $carrierData)) {
                        $result[$key]["origin_address"] = $carrierData["originAddress"];
                    }
                }
            }
        } catch (\Exception $e) {
            // Skip adding the origin address in response for other shipping methods
        }

        return $result;
    }

    /**
     * Format order model for graphql schema
     *
     * @param Order $subject
     * @param array $result
     * @param OrderInterface $orderModel
     * @return array
     */
    public function afterFormat(
        Order $subject,
        $result,
        OrderInterface $orderModel
    ): array {

        try {
            $hqOrderModel = $this->shipperHqOrderDetail->create();

            $carrierData = $hqOrderModel->loadByOrder($orderModel->getId())->getFirstItem();

            $carrierData = $this->shipperDataHelper->decodeShippingDetails(
                $carrierData->getCarrierGroupDetail()
            );

            $carrierData = end($carrierData);

            if (!empty($result)) {
                //shqpickup1_collect shipping
                if (str_contains($orderModel->getShippingMethod(), self::SHIPPERHQ_CODE) && is_array($carrierData) && array_key_exists("originAddress", $carrierData)) {
                    $result["shipping_origin_address"] = $carrierData["originAddress"];
                }
            }
        } catch (\Exception $e) {
            // Skip adding the origin address in response for other shipping methods
        }
        return $result;
    }
}
