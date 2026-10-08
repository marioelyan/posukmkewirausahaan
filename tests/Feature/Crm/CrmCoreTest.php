<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\CustomerCampaign;
use App\Models\CustomerCampaignLog;
use App\Models\CustomerSegment;
use App\Models\Receivable;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CrmCoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'customer-segments-access',
            'customer-segments-create',
            'customer-segments-update',
            'customer-segments-delete',
            'crm-campaigns-access',
            'crm-campaigns-create',
            'crm-campaigns-update',
            'crm-campaigns-delete',
            'crm-reminders-access',
            'customers-edit',
        ] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_user_can_create_manual_segment_and_assign_it_to_customer(): void
    {
        $user = $this->createUserWithPermissions([
            'customer-segments-create',
            'customers-edit',
        ]);
        $customer = $this->createCustomer([
            'name' => 'Customer Segment Manual',
            'no_telp' => '628111000001',
        ]);

        $this->actingAs($user)
            ->post(route('customer-segments.store'), [
                'name' => 'VIP Offline',
                'type' => CustomerSegment::TYPE_MANUAL,
                'is_active' => true,
                'description' => 'Tag manual untuk pelanggan prioritas.',
            ])
            ->assertRedirect(route('customer-segments.index'));

        $segment = CustomerSegment::query()->where('slug', 'vip-offline')->firstOrFail();

        $this->actingAs($user)
            ->put(route('customers.segments.sync', $customer), [
                'segment_ids' => [$segment->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('customer_segment_memberships', [
            'customer_id' => $customer->id,
            'customer_segment_id' => $segment->id,
            'source' => 'manual',
        ]);
    }

    public function test_crm_generate_reminders_command_syncs_auto_segments_and_creates_reminder_campaigns(): void
    {
        $today = now()->startOfDay();
        $inactiveHighSpender = $this->createCustomer([
            'name' => 'High Spender Inactive',
            'no_telp' => '628111000002',
            'loyalty_total_spent' => 2000000,
            'loyalty_transaction_count' => 8,
            'last_purchase_at' => $today->copy()->subDays(31),
        ]);
        $frequentBuyer = $this->createCustomer([
            'name' => 'Frequent Buyer',
            'no_telp' => '628111000003',
            'loyalty_total_spent' => 500000,
            'loyalty_transaction_count' => 7,
            'last_purchase_at' => $today->copy()->subDays(10),
        ]);
        $creditCustomer = $this->createCustomer([
            'name' => 'Credit Customer',
            'no_telp' => '628111000004',
            'loyalty_total_spent' => 700000,
            'loyalty_transaction_count' => 2,
            'last_purchase_at' => $today->copy()->subDays(5),
        ]);
        $dueSoonCustomer = $this->createCustomer([
            'name' => 'Due Soon Customer',
            'no_telp' => '628111000005',
            'loyalty_total_spent' => 300000,
            'loyalty_transaction_count' => 1,
            'last_purchase_at' => $today->copy()->subDays(2),
        ]);

        $overdueReceivable = Receivable::create([
            'customer_id' => $creditCustomer->id,
            'invoice' => 'RCV-OVERDUE-001',
            'total' => 150000,
            'paid' => 0,
            'due_date' => $today->copy()->subDay(),
            'status' => 'unpaid',
        ]);
        $dueSoonReceivable = Receivable::create([
            'customer_id' => $dueSoonCustomer->id,
            'invoice' => 'RCV-DUESOON-001',
            'total' => 175000,
            'paid' => 0,
            'due_date' => $today->copy()->addDays(2),
            'status' => 'unpaid',
        ]);

        $this->artisan('crm:generate-reminders')
            ->assertExitCode(0);

        $highSpender = CustomerSegment::query()->where('slug', 'high_spender')->firstOrFail();
        $frequent = CustomerSegment::query()->where('slug', 'frequent_buyer')->firstOrFail();
        $inactive = CustomerSegment::query()->where('slug', 'inactive_customer')->firstOrFail();
        $credit = CustomerSegment::query()->where('slug', 'credit_customer')->firstOrFail();
        $overdue = CustomerSegment::query()->where('slug', 'overdue_customer')->firstOrFail();

        $this->assertDatabaseHas('customer_segment_memberships', [
            'customer_id' => $inactiveHighSpender->id,
            'customer_segment_id' => $highSpender->id,
            'source' => 'auto',
        ]);
        $this->assertDatabaseHas('customer_segment_memberships', [
            'customer_id' => $inactiveHighSpender->id,
            'customer_segment_id' => $inactive->id,
            'source' => 'auto',
        ]);
        $this->assertDatabaseHas('customer_segment_memberships', [
            'customer_id' => $frequentBuyer->id,
            'customer_segment_id' => $frequent->id,
            'source' => 'auto',
        ]);
        $this->assertDatabaseHas('customer_segment_memberships', [
            'customer_id' => $creditCustomer->id,
            'customer_segment_id' => $credit->id,
            'source' => 'auto',
        ]);
        $this->assertDatabaseHas('customer_segment_memberships', [
            'customer_id' => $creditCustomer->id,
            'customer_segment_id' => $overdue->id,
            'source' => 'auto',
        ]);

        $this->assertDatabaseHas('customer_campaigns', [
            'context_key' => 'due-soon-'.$today->toDateString(),
            'type' => CustomerCampaign::TYPE_DUE_DATE_REMINDER,
        ]);
        $this->assertDatabaseHas('customer_campaigns', [
            'context_key' => 'overdue-'.$today->toDateString(),
            'type' => CustomerCampaign::TYPE_DUE_DATE_REMINDER,
        ]);
        $this->assertDatabaseHas('customer_campaigns', [
            'context_key' => 'repeat-order-'.$today->toDateString(),
            'type' => CustomerCampaign::TYPE_REPEAT_ORDER_REMINDER,
        ]);

        $this->assertDatabaseHas('customer_campaign_logs', [
            'customer_id' => $creditCustomer->id,
            'receivable_id' => $overdueReceivable->id,
            'status' => CustomerCampaignLog::STATUS_READY_TO_SEND,
        ]);
        $this->assertDatabaseHas('customer_campaign_logs', [
            'customer_id' => $dueSoonCustomer->id,
            'receivable_id' => $dueSoonReceivable->id,
            'status' => CustomerCampaignLog::STATUS_READY_TO_SEND,
        ]);
        $this->assertDatabaseHas('customer_campaign_logs', [
            'customer_id' => $inactiveHighSpender->id,
            'status' => CustomerCampaignLog::STATUS_READY_TO_SEND,
        ]);
    }

    public function test_transaction_share_campaign_is_idempotent(): void
    {
        $user = $this->createUserWithPermissions([
            'crm-campaigns-create',
        ]);
        $customer = $this->createCustomer([
            'name' => 'Customer Share Invoice',
            'no_telp' => '628111000006',
        ]);
        $transaction = Transaction::create([
            'cashier_id' => $user->id,
            'customer_id' => $customer->id,
            'invoice' => 'TRX-CRM-001',
            'cash' => 150000,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 150000,
        ]);

        $this->actingAs($user)
            ->post(route('transactions.share-campaign', $transaction))
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('transactions.share-campaign', $transaction))
            ->assertRedirect();

        $this->assertSame(
            1,
            CustomerCampaign::query()
                ->where('context_key', 'invoice-share-transaction-'.$transaction->id)
                ->count()
        );
        $this->assertSame(
            1,
            CustomerCampaignLog::query()
                ->where('transaction_id', $transaction->id)
                ->count()
        );
    }

    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function createCustomer(array $attributes = []): Customer
    {
        return Customer::create([
            'name' => 'Customer CRM',
            'no_telp' => '628111999999',
            'address' => 'Jl. CRM Test',
            'loyalty_total_spent' => 0,
            'loyalty_transaction_count' => 0,
            ...$attributes,
        ]);
    }
}
