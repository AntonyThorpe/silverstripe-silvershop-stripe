<?php

namespace Innoweb\SilvershopStripe\Model;

use Innoweb\SilvershopStripe\Omnipay\Message\FetchCardRequest;
use Omnipay\Common\Http\Client as OmnipayClient;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Omnipay\GatewayInfo;
use SilverStripe\Omnipay\Model\Payment;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Member;
use SilverStripe\View\ArrayData;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class CreditCard extends DataObject
{
    private static string $table_name = 'CreditCard';

    private static array $db = [
        'CardReference' => 'Varchar(50)',
    ];

    private static array $has_one = [
        'Member' => Member::class,
    ];

    protected $card_details = null;

    public function getCardDetails()
    {
        if (!$this->card_details
            && $this->CardReference
            && $this->Member()
            && $this->Member()->StripeCustomerReference
        ) {
            try {
                // load data from API
                $data = [];

                $gatewayName = 'Stripe';
                if ($gateways = Config::inst()->get(Payment::class, 'allowed_gateways')) {
                    $gatewayName = $gateways[0];
                }

                $gatewayFactory = Injector::inst()->get(\Omnipay\Common\GatewayFactory::class);
                $gateway = $gatewayFactory->create($gatewayName);
                $parameters = GatewayInfo::getParameters($gatewayName);
                if (is_array($parameters)) {
                    $gateway->initialize($parameters);
                }

                $obj = new FetchCardRequest(new OmnipayClient(), SymfonyRequest::createFromGlobals());
                $fetchCardRequest = $obj->initialize(array_replace($gateway->getParameters(), $parameters ?? []));
                $fetchCardRequest->setCustomerReference($this->Member()->StripeCustomerReference);
                $fetchCardRequest->setCardReference($this->CardReference);

                $response = $fetchCardRequest->send();
                if ($response->isSuccessful()) {
                    $responseData = $response->getData();
                    $data = [
                        'Brand' => $responseData['card']['brand'] ?? null,
                        'LastFourDigits' => $responseData['card']['last4'] ?? null,
                        'ExpiryMonth' => $responseData['card']['exp_month'] ?? null,
                        'ExpiryYear' => $responseData['card']['exp_year'] ?? null,
                    ];
                    $this->card_details = ArrayData::create($data);
                } else {
                    Injector::inst()->get(LoggerInterface::class)->error('CreditCard::getCardDetails: responce failed: ' . $response->getMessage());
                }
            } catch (Exception $e) {
                Injector::inst()->get(LoggerInterface::class)->error($e->getMessage());
            }
        }

        return $this->card_details;
    }

    public function getTitle(): ?string
    {
        if ($data = $this->getCardDetails()) {
            return $data->getField('Brand') . ' ****' . $data->getField('LastFourDigits') . ' ' . $data->getField('ExpiryMonth') . '/' . $data->getField('ExpiryYear');
        }

        return 'Data could not be loaded';
    }

    public function onAfterBuild(): void
    {
        parent::onAfterBuild();

        // check if fields exist
        $count = DB::query('SHOW COLUMNS FROM "CreditCard" LIKE \'LastFourDigits\'')->numRecords();
        if ($count > 0) {
            DB::query('ALTER TABLE "CreditCard" DROP COLUMN LastFourDigits, DROP COLUMN Brand, DROP COLUMN ExpMonth, DROP COLUMN ExpYear');
        }
    }
}
