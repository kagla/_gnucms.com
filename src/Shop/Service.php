<?php

declare(strict_types=1);

namespace GnuCms\Shop;

use GnuCms\App;
use GnuCms\Shop\Catalog\Categories;
use GnuCms\Shop\Catalog\Options;
use GnuCms\Shop\Fulfillment\ManualCsvCarrierAdapter;
use GnuCms\Payment\ManualSettlementAdapter;

final class Service
{
    public const PUBLIC_PREFIX = '/shop';
    public const ADMIN_PREFIX = '/admin/shop';

    public readonly Store $store;
    public readonly Settings $settings;
    public readonly Categories $categories;
    public readonly Options $options;
    public readonly Images $images;
    public readonly HomeBanner $banner;
    public readonly Catalog\Products $products;
    public readonly Catalog\Listing $listing;
    public readonly Commerce\Cart $cart;
    public readonly Commerce\Orders $orders;
    public readonly Commerce\Payments $payments;
    public readonly Commerce\CheckoutIntents $checkoutIntents;
    public readonly Commerce\Fulfillment $fulfillment;
    public readonly Commerce\Reports $reports;
    public readonly Commerce\Settlements $settlements;
    public readonly ProductFeedback $feedback;

    public function __construct(public readonly App $app)
    {
        $this->store = new Store($app->db());
        $this->settings = new Settings($this->store, $app->htmlSanitizer(), $app->paymentProviders());
        $this->categories = new Categories($this->store, $app->htmlSanitizer(), $this->settings, $app->contentImages());
        $this->options = new Options($this->store);
        $this->images = new Images($app, $this->settings);
        $this->banner = new HomeBanner($this->store, $this->settings, $this->images);
        $this->products = new Catalog\Products($this->store, $app->htmlSanitizer(), $app->contentImages(), $this->images, $this->options, $this->categories);
        $this->listing = new Catalog\Listing($this->store, $this->settings, $this->options);
        $this->cart = new Commerce\Cart($this->products, $this->settings, $this->store);
        $this->orders = new Commerce\Orders($this->store, $this->cart, $this->settings, (string) $app->cmsService()->settings()['timezone'],
            fn (int $userId, int $orderId, string $status, string $number, array $vars = []) => $app->notificationService()->notifyOrderChannels($userId, $orderId, $status, $number, $vars));
        $this->payments = new Commerce\Payments($app, $this->settings, $this->orders);
        $this->checkoutIntents = new Commerce\CheckoutIntents($app, $this->cart, $this->orders, $this->payments);
        $this->fulfillment = new Commerce\Fulfillment($this->store, $this->orders, new ManualCsvCarrierAdapter());
        $this->reports = new Commerce\Reports($this->store);
        $this->settlements = new Commerce\Settlements($this->store, new ManualSettlementAdapter($app->paymentProviders()));
        $this->feedback = new ProductFeedback($this->store,
            fn (array $feedback, array $product) => $app->notificationService()->notifyInquiryReply($feedback, $product));
    }
}
