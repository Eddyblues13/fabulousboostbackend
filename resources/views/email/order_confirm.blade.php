<h2>Order Confirmation</h2>
<p>Hello {{ $user->username }},</p>
<p>Your order has been submitted successfully.</p>

<ul>
    <li><strong>Order ID:</strong> {{ $order_id }}</li>
    <li><strong>Date:</strong> {{ $order_at }}</li>
    <li><strong>Service:</strong> {{ $service }}</li>
    <li><strong>Status:</strong> {{ $status }}</li>
    <li><strong>Amount Paid:</strong> {{ $paid_amount }} {{ $currency }}</li>
    <li><strong>Transaction ID:</strong> {{ $transaction }}</li>
    <li><strong>Remaining Balance:</strong> {{ $remaining_balance }} {{ $currency }}</li>
</ul>

<p>Thank you for your order.</p>