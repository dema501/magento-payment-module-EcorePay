<?php
/**
 *
 * @category   Mage
 * @package    Liftmode_EcorePay
 * @copyright  Copyright (c)  Dmitry Bashlov, contributors.
 */

class Liftmode_EcorePay_Model_Async extends Mage_Core_Model_Abstract
{
    private $_model;

    public function __construct()
    {
        parent::__construct();
        $this->_model = Mage::getModel('ecorepay/method_ecorePay');
    }

    /**
     * Poll Amazon API to receive order status and update Magento order.
     */
    public function syncOrderStatus(Mage_Sales_Model_Order $order, $isManualSync = false)
    {
        try {
            $data = $this->_model->_doGetStatus($order->getPayment());

            $this->_model->log(array('syncOrderStatus------>>>', $data, $order->getIncrementId()));

            if (!empty($data)) {
                $this->putOrderOnProcessing($order);
            } else {
                $this->putOrderOnHold($order, 'No transaction found, you should manually make invoice');
            }

        } catch (Exception $e) {
//            $this->putOrderOnHold($order);
            Mage::logException($e);
        }
    }

    /**
     * Magento cron to sync Amazon orders
     */
    public function cron()
    {

        if ($this->_model->getConfigData('active') && $this->_model->getConfigData('async')) {
            $orderCollection = Mage::getModel('sales/order_payment')
                ->getCollection()
                ->join(array('order'=>'sales/order'), 'main_table.parent_id=order.entity_id', 'state')
                ->addFieldToFilter('method', 'ecorepay')
                ->addFieldToFilter('state',  array('in' => array(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, Mage_Sales_Model_Order::STATE_PROCESSING)))
                ->addFieldToFilter('status', Mage_Index_Model_Process::STATUS_PENDING)
        ;

            $this->_model->log(array('run sql------>>>', $orderCollection->getSelect()->__toString()));

            foreach ($orderCollection as $orderRow) {
                $order = Mage::getModel('sales/order')->load($orderRow->getParentId());

                $this->_model->log(array('found order------>>>', 'IncrementId' => $order->getIncrementId(), 'Status' => $order->getStatus(), 'State' => $order->getState()));

                $this->syncOrderStatus($order);
            }
        }
    }

    public function putOrderOnProcessing(Mage_Sales_Model_Order $order)
    {
        $this->_model->log(array('putOrderOnProcessing------>>>', 'IncrementId' => $order->getIncrementId()));

        // Change order to "On Process"
        if ($order->canShip()) {
            // Save the payment changes
            try {
                $payment = $order->getPayment();
                $payment->setIsTransactionClosed(1);
                $payment->save();

                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING);
                $order->setStatus('processing');

                $order->addStatusToHistory($order->getStatus(), 'We recieved your payment, thank you!', true);
                $order->save();

                Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('ecorepay')->__('We recieved your payment for order id: %s. Order was paid by EcorePay', $order->getIncrementId()));
            } catch (Exception $e) {
                $this->_model->log(array('putOrderOnProcessing---->>>>', $e->getMessage()));
            }
        }
    }

    public function putOrderOnHold(Mage_Sales_Model_Order $order, $reason)
    {
        $this->_model->log(array('putOrderOnHold------>>>', 'IncrementId' => $order->getIncrementId()));

        // Change order to "On Hold"
        try {
            $order->hold();
            $order->addStatusToHistory($order->getStatus(), $reason, false);
            $order->save();
        } catch (Exception $e) {
            $this->_model->log(array('putOrderOnHold---->>>>', $e->getMessage()));
        }
    }
}
