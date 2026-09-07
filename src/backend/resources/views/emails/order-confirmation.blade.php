<!DOCTYPE html>
<html>
<body>
    <p>Hi {{ $order->user->name }},</p>

    <p>Your order #{{ $order->id }} has been confirmed and paid.</p>

    <ul>
        @foreach ($order->items as $item)
            <li>{{ $item->product_name }} × {{ $item->quantity }} — {{ number_format($item->line_total_cents / 100, 2) }}</li>
        @endforeach
    </ul>

    <p><strong>Total: {{ number_format($order->total_cents / 100, 2) }}</strong></p>

    <p>Shipping to:<br>{{ $order->shipping_address }}</p>
</body>
</html>
