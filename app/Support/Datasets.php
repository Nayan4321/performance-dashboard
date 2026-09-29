<?php

namespace App\Support;

/**
 * Registry of the data sets a dashboard widget can report on. Adding a new
 * source (e.g. a new CallGear table) means adding one entry here.
 */
class Datasets
{
    public static function all(): array
    {
        return [
            'appointments' => [
                'label' => 'Appointments (Zenoti)',
                'source' => 'Zenoti',
                'table' => 'appointments',
                'date' => 'start_time',
                'employee' => 'employee_id',
                'numeric' => ['price' => 'Price'],
                'groups' => ['branch' => 'Branch', 'employee' => 'Employee', 'status' => 'Status', 'service_name' => 'Service', 'day' => 'Day', 'week' => 'Week', 'weekday' => 'Day of week', 'month' => 'Month'],
                'filters' => ['status' => 'Status', 'service_name' => 'Service'],
            ],
            'bookings' => [
                'label' => 'Bookings (appointments by date booked)',
                'source' => 'Zenoti',
                'table' => 'appointments',
                'date' => 'booked_at',
                'employee' => 'employee_id',
                'numeric' => ['price' => 'Price'],
                'groups' => ['branch' => 'Branch', 'employee' => 'Employee', 'status' => 'Status', 'service_name' => 'Service', 'day' => 'Day', 'week' => 'Week', 'weekday' => 'Day of week', 'month' => 'Month'],
                'filters' => ['status' => 'Status', 'service_name' => 'Service'],
            ],
            'sales' => [
                'label' => 'Sales (Zenoti)',
                'source' => 'Zenoti',
                'table' => 'sales',
                'date' => 'sold_at',
                'employee' => 'employee_id',
                'numeric' => ['net_amount' => 'Net amount', 'gross_amount' => 'Gross amount', 'discount' => 'Discount', 'quantity' => 'Quantity'],
                'groups' => ['branch' => 'Branch', 'employee' => 'Employee', 'category' => 'Category', 'item_type' => 'Item type (raw)', 'item_name' => 'Item', 'status' => 'Status', 'day' => 'Day', 'week' => 'Week', 'weekday' => 'Day of week', 'month' => 'Month'],
                'filters' => ['category' => 'Category (Service, Product, Package, Gift card, Membership, Prepaid card)', 'item_type' => 'Item type (raw)', 'status' => 'Status'],
            ],
            'collections' => [
                'label' => 'Collections / payments (Zenoti)',
                'source' => 'Zenoti',
                'table' => 'collections',
                'date' => 'collected_at',
                'employee' => 'employee_id',
                'numeric' => ['amount' => 'Amount'],
                'groups' => ['branch' => 'Branch', 'employee' => 'Employee', 'payment_type' => 'Payment type', 'day' => 'Day', 'month' => 'Month'],
                'filters' => ['payment_type' => 'Payment type'],
            ],
            'leads' => [
                'label' => 'Leads (Zenoti / CallGear / manual)',
                'source' => 'Zenoti + CallGear + manual',
                'table' => 'leads',
                'date' => 'lead_at',
                'employee' => 'employee_id',
                'numeric' => ['value' => 'Value'],
                'groups' => ['stage' => 'Stage', 'branch' => 'Branch', 'employee' => 'Employee', 'source' => 'Source', 'channel' => 'Channel', 'day' => 'Day', 'month' => 'Month'],
                'filters' => ['stage' => 'Stage', 'source' => 'Source', 'channel' => 'Channel'],
            ],
            'calls' => [
                'label' => 'Calls (CallGear)',
                'source' => 'CallGear',
                'table' => 'calls',
                'date' => 'started_at',
                'employee' => 'employee_id',
                'numeric' => ['duration_seconds' => 'Duration (sec)', 'wait_seconds' => 'Wait time (sec)'],
                'groups' => ['branch' => 'Branch', 'employee' => 'Employee', 'status' => 'Status', 'direction' => 'Direction', 'day' => 'Day', 'month' => 'Month'],
                'filters' => ['status' => 'Status', 'direction' => 'Direction'],
            ],
            'guests' => [
                'label' => 'Guests / new clients (Zenoti)',
                'source' => 'Zenoti',
                'table' => 'guests',
                'date' => 'registered_at',
                'employee' => null,
                'numeric' => [],
                'groups' => ['branch' => 'Branch', 'gender' => 'Gender', 'day' => 'Day', 'month' => 'Month'],
                'filters' => ['gender' => 'Gender'],
            ],
            'employees' => [
                'label' => 'Employees',
                'source' => 'Zenoti + CallGear',
                'table' => 'employees',
                'date' => null,
                'employee' => 'id',
                'numeric' => [],
                'groups' => ['branch' => 'Branch', 'job_title' => 'Job title', 'source' => 'Source'],
                'filters' => ['job_title' => 'Job title', 'source' => 'Source', 'is_active' => 'Active (1/0)'],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return static::all()[$key] ?? null;
    }
}
