# Twispay Credit Card Payments for Magento 1
- Twispay Credit Card Payments is the official payment module built for Magento 1.

## Description
[Twispay](https://www.twispay.com) is a European certified acquiring bank with a sleek payment gateway optimized for online shops. We process payments from worldwide customers using Mastercard or Visa debit and credit cards. Increase your purchases by using our conversion rate optimized checkout flow and manage your transactions with our dashboard created specifically for online merchants like you.

Our Magento 1 payment extension allows for fast and easy integration with the Twispay Payment Gateway. Quickly start accepting online credit card payments through a secure environment and a fully customizable checkout process. Give your customers the shopping experience they expect, and boost your online sales with our simple and elegant payment plugin.

For more details concerning our pricing in your region, please check out our [pricing page](https://www.twispay.com/pricing). To use our payment module and start processing you will need a Twispay [merchant account](https://merchant-stage.twispay.com/auth/signup). For any assistance during the on-boarding process, our [sales and compliance](https://twispay.com/en/contact/) team are happy to respond to any enquiries you may have.

## PCI Compliance:
Twispay is a Level 1 certified PCI-DSS Compliant payment gateway, ensuring that all cardholders’ transactions are securely processed according to the highest standards in the payments industry. Integrations to our gateway are done through Twispay’s flexible Hosted Payment Page, aiming for the most positive user experience through complete personalization, while relieving the merchant from storing or processing sensitive cardholder data. This means that card holders will only enter credit card details such as credit card number or CVV on Twispay’s secured payment page, thus eliminating merchant’s necessity for PCI compliance.

## Merchant Benefits:
- Quick and easy installation process
- Seamless integration to new and existing e-shops
- Fully customizable checkout (logo, colors or full HTML/CSS template)
- Secure credit card processing in a PCI-DSS compliant environment
- More business through continuously optimized payment flows
- The most competitive rates

## Customer Benefits:
- Safe payments – peace of mind while paying online
- Instant billing and receipts – faster shipments and delivery
- Smooth purchase flow – straightforward shopping experience

We take pride in offering world class, free customer support to all our merchants during the integration phase, and at any time thereafter. Our support team is available non-stop during regular business hours EET.

## Installation
The easiest way of installing our module is by visiting the [official module page](https://marketplace.magento.com/twispay-twispay-tpay.html).
Alternatively, you can check out our [installation guide](#) for detailed step by step instructions.
1. Login to the official Magento website and [login](https://account.magento.com/customer/account/login)
2. Access the [marketplace](https://marketplace.magento.com/)
3. Search for the **twispay** keyword
4. Open [Twispay Credit Card Payments](https://marketplace.magento.com/twispay-twispay-tpay.html) for **Magento 1**
5. Select you **Magento 1 version** and add it to cart
6. Go to checkout and click on **Place Order**
7. Click on **Install**
8. Copy the **Access Key** by clicking the **Copy** button from the **Action** column
9. Login to your **Magento 1** admin panel
10. Go to **System** -> **Magento Connect** -> **Magento Connect Manager**
11. Re-enter you **administrator credentials** and login
12. Paste the copied **Access Key** inside the **Paste extension key to install** and click on the **Install** button
13. Wait for the installation to finish
14. Go back to your **Magento 1** admin panel by clicking on the **Return to Admin** link
15. Go to **System** -> **Configuration**
16. Go to **Payment Methods**
17. Select **Yes** under **Live mode**. _(Unless you are testing)_
18. Enter your **Site ID**. _(You can get one from [here for live](https://merchant.twispay.com/login) or from [here for stage](https://merchant-stage.twispay.com/login))_
19. Enter your **Private Key**. _(You can get one from [here for live](https://merchant.twispay.com/login) or from [here for stage](https://merchant-stage.twispay.com/login))_
20. Enter your support **Contact Email**. _(This will be displayed to customers in case of a payment error)_
21. Save your changes.

## Changelog

#### 1.0.3:
- **Compatible with Open Source (CE) :** 1.7 1.8 1.8.1 1.9 1.9.1 1.9.2 1.9.3 1.9.4
- **Stability:** Stable Build
- **Description:** The official Twispay Payment Gateway extension for Magento 1.x - v.1.0.2
- **Release Notes & Released functionality:**
    - Improved the 'form' template.
    - Personalized the orders identifier to make it more framework specific.
    - Ensured that the "orderId" sent inside the request payment is always unique.
    - Updated the readme file.
    - Fixed the display of the administrator email address in case of payment failure.
    - Added check that excludes orders with the amount below or equal to zero.

#### 1.0.2:
- **Compatible with Open Source (CE) :** 1.7 1.8 1.8.1 1.9 1.9.1 1.9.2 1.9.3 1.9.4
- **Stability:** Stable Build
- **Description:** The official Twispay Payment Gateway extension for Magento 1.x - v.1.0.2
- **Release Notes & Released functionality:**
    - Redesigned the configuration page to make it more user-friendly.
    - Updated the checksum calculation algorithm.
    - Added logic to process all the Twispay response states.
    - Moved helper code to the module default helper.
    - Added debug and error logging. Error login will always be printed and debug logs are available only when logging is enabled.
    - Added contact email field to the configuration page.
    - Added checkout success and failure pages.
    - Added en_US lang file.
    - Added methods and config option for authorize and capture payment steps.
    - Added functionality to create a transaction after each successful payment.
    - Added purchase order type refund and partial refund support.
    - Added recurring order type refund and partial refund support.
    - Added daily sync with the Twispay server for recurring profiles.

#### 1.0.0:
- **Compatible with Open Source (CE) :** 1.7 1.8 1.8.1 1.9 1.9.1 1.9.2 1.9.3
- **Stability:** Stable Build
- **Description:** The official Twispay Payment Gateway extension for Magento 1.x - v.1.0.0
- **Release Notes:**
    - This Module Enables Magento 1.x Website Owners, quickly and reliably accept online payments via credit or debit cards.
    All payments will be processed in a secure PCI-DSS compliant environment so merchants will rely on Twispay's secure hosted payment page.
- **Released functionality:**
    - Configuration interface for the merchant where they enter the parameters requested by Twispay
    - Integration with Twispay’s Secure Hosted Payment Page
    - Redirect page for result of a payment
    - Listening URL which accepts the server’s Instant Payment Notifications
