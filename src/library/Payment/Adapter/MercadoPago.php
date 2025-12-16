<?php

/**
 * Adaptador de Pagamento Mercado Pago - Checkout Pro
 */

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;

class Payment_Adapter_MercadoPago extends Payment_AdapterAbstract implements FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    /**
     * Define o formulário de configuração no Painel Admin
     */
    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'description' => 'Integração oficial Checkout Pro. O cliente é redirecionado para pagar no Mercado Pago.',
            'logo' => [
                'logo' => 'mercadopago.png',
                'height' => '30px',
                'width' => '65px',
            ],
            'form' => [
                'public_key' => [
                    'text', [
                        'label' => 'Public Key',
                        'description' => 'Chave Pública da aplicação.',
                        'required' => true,
                    ],
                ],
                'access_token' => [
                    'password', [
                        'label' => 'Access Token',
                        'description' => 'Token de Acesso.',
                        'required' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Gera o link de pagamento (Preferência) e o botão HTML
     */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        // Carrega a fatura e serviços necessários
        $invoice = $this->di['db']->load('Invoice', $invoice_id);
        $invoiceService = $this->di['mod_service']('Invoice');
        
        // Configura a SDK com o Access Token salvo
        $accessToken = $this->getParam('access_token');
        MercadoPagoConfig::setAccessToken($accessToken);

        try {
            $client = new PreferenceClient();
            
            // Monta os dados da Preferência
            $preferenceRequest = [
                "items" => [
                    [
                        "id" => (string)$invoice->id,
                        "title" => "Fatura #" . $invoice->nr,
                        "description" => "Pagamento de serviços FOSSBilling",
                        "quantity" => 1,
                        "unit_price" => (float)$invoiceService->getTotalWithTax($invoice),
                        "currency_id" => "BRL"
                    ]
                ],
                "payer" => [
                    "email" => $invoice->buyer_email,
                    "name"  => $invoice->buyer_first_name,
                    "surname" => $invoice->buyer_last_name,
                ],
                "back_urls" => [
                    "success" => $this->getParam('return_url'),
                    "failure" => $this->getParam('return_url'),
                    "pending" => $this->getParam('return_url')
                ],
                "external_reference" => (string)$invoice->id,
                "statement_descriptor" => "FOSSBILLING",
            ];

            // Envia para o Mercado Pago
            $preference = $client->create($preferenceRequest);

            // Retorna o Botão para o cliente (Direto, sem verificações)
            return sprintf(
                '<div class="text-center" style="margin-top: 20px;">
                    <a href="%s" class="btn btn-primary btn-lg" target="_blank" style="text-decoration: none;">
                        Pagar com Mercado Pago
                    </a>
                </div>',
                $preference->init_point
            );

        } catch (MPApiException $e) {
            // Erro específico da API
            $content = $e->getApiResponse()->getContent();
            $msg = $content['message'] ?? 'Erro desconhecido na API';
            error_log("Mercado Pago API Error: " . json_encode($content));
            
            return '<div class="alert alert-danger">Erro ao conectar com Mercado Pago: ' . $msg . '</div>';
            
        } catch (\Exception $e) {
            // Erro genérico
            return '<div class="alert alert-danger">Erro interno: ' . $e->getMessage() . '</div>';
        }
    }
}