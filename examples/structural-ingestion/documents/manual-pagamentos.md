# Manual de pagamentos

Este documento descreve o fluxo de captura e recuperação de pagamentos da plataforma.

## Autenticação

Todas as requisições devem enviar um token no cabeçalho `Authorization`.

```http
Authorization: Bearer token-da-aplicacao
Content-Type: application/json
```

O token deve pertencer ao mesmo tenant responsável pelo pedido.

## Captura de pagamento

Para capturar um pagamento, envie o identificador do pedido e o valor autorizado.

1. Valide o status atual do pedido.
2. Confirme que o token do cartão ainda é válido.
3. Execute a captura uma única vez.
4. Registre o identificador retornado pelo gateway.

## Erro ERR_PAYMENT_1047

O código `ERR_PAYMENT_1047` indica que o token do cartão expirou antes da captura.

> Não repita a captura usando o token expirado.

O serviço deve renovar o token e repetir a operação com a mesma chave de idempotência.

```php
final class PaymentRecoveryService
{
    public function recover(string $orderId, string $idempotencyKey): void
    {
        $token = $this->tokens->renewForOrder($orderId);
        $this->gateway->capture($orderId, $token, $idempotencyKey);
    }
}
```

## Códigos de resposta

| Código | Significado | Ação recomendada |
|---|---|---|
| `200` | Pagamento capturado | Persistir o identificador da captura |
| `401` | Token da aplicação inválido | Renovar a credencial da aplicação |
| `409` | Operação já processada | Consultar a captura pela chave de idempotência |
| `422` | Token do cartão expirado | Executar o fluxo de recuperação |

## Segurança

Nunca registre o token completo do cartão, credenciais da aplicação ou dados sensíveis do comprador.
