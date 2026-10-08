<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerCampaignLog extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY_TO_SEND = 'ready_to_send';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'customer_campaign_id',
        'customer_id',
        'transaction_id',
        'receivable_id',
        'channel',
        'status',
        'payload',
        'sent_at',
    ];

    protected $casts = [
        'customer_campaign_id' => 'integer',
        'customer_id' => 'integer',
        'transaction_id' => 'integer',
        'receivable_id' => 'integer',
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(CustomerCampaign::class, 'customer_campaign_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function receivable()
    {
        return $this->belongsTo(Receivable::class);
    }
}
