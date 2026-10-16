<!doctype html><html lang="en"><head><meta charset="utf-8"><title>{{ $title }}</title></head>
<body style="font-family:Arial,sans-serif;color:#1b1d20;line-height:1.6;max-width:640px;margin:24px auto;padding:16px">
<p>{{ config('app.name') }}</p><h1>{{ $title }}</h1><p>Order {{ $order['code'] }}</p>
<p>{{ $order['contact']['first_name'] }} {{ $order['contact']['last_name'] }}</p>
<p>Delivery: {{ implode(', ', $order['delivery']) }}</p>
@foreach($order['lines'] as $line)
<section><h2>{{ $line['title'] }}</h2><p>{{ $line['description'] }}</p>
<p>{{ $line['quantity'] }} × {{ $money($line['payable_unit_minor']) }} AUD · {{ $money($line['line_payable_minor']) }} AUD</p>
@foreach($line['selected_options'] as $label => $value)<p>{{ $label }}: {{ $value }}</p>@endforeach
@foreach($line['facts'] as $fact)<p>{{ $fact['label'] }}: {{ $fact['value'] }}</p>@endforeach
</section>
@endforeach
<p>Subtotal: {{ $money($order['subtotal_minor']) }} AUD</p><p>Discount: {{ $money($order['discount_minor']) }} AUD</p>
<p>Tax: {{ $money($order['tax_minor']) }} AUD</p><p>Delivery: {{ $money($order['delivery_minor']) }} AUD</p>
<p><strong>Total: {{ $money($order['total_minor']) }} AUD</strong></p>
<p><a href="{{ $link }}" rel="noreferrer">View your order</a></p>
</body></html>
