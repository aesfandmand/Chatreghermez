<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Access;

/**
 * Registry of every capability and the DEFAULT role matrix.
 * The site admin can change the live matrix from the panel; defaults only seed new roles.
 * The WordPress "administrator" role always holds every capability (cannot be locked out).
 */
final class Capabilities
{
    public const ORDERS_VIEW        = 'crc_orders_view';
    public const ORDERS_CREATE_FOR  = 'crc_orders_create_for_customer';
    public const ORDERS_EDIT        = 'crc_orders_edit';
    public const ORDERS_CANCEL      = 'crc_orders_cancel';
    public const ORDERS_REFUND      = 'crc_orders_refund_manual';
    public const INVOICES_VIEW      = 'crc_invoices_view';
    public const INVOICES_ISSUE     = 'crc_invoices_issue';
    public const INVOICES_APPROVE   = 'crc_invoices_approve';
    public const INVOICES_CORRECT   = 'crc_invoices_correct';
    public const PAYMENTS_VIEW      = 'crc_payments_view';
    public const PAYMENTS_RECORD    = 'crc_payments_record_manual';
    public const CHEQUES_APPROVE    = 'crc_cheques_approve';
    public const CATALOG_VIEW       = 'crc_catalog_view';
    public const CATALOG_EDIT       = 'crc_catalog_edit';
    public const DISCOUNTS_MANAGE   = 'crc_discounts_manage';
    public const CUSTOMERS_VIEW     = 'crc_customers_view';
    public const CUSTOMERS_EDIT     = 'crc_customers_edit';
    public const MEDIA_VIEW         = 'crc_media_view';
    public const MEDIA_EDIT         = 'crc_media_edit';
    public const TASKS_DESIGN       = 'crc_tasks_design';
    public const TASKS_INSTALL      = 'crc_tasks_install';
    public const SMS_SEND           = 'crc_sms_send';
    public const SMS_SETTINGS       = 'crc_sms_settings';
    public const GATEWAY_SETTINGS   = 'crc_gateway_settings';
    public const REPORTS_VIEW       = 'crc_reports_view';
    public const AUDIT_VIEW         = 'crc_audit_view';
    public const ROLES_VIEW         = 'crc_roles_view';
    public const ROLES_MANAGE       = 'crc_roles_manage';

    public const ROLE_CUSTOMER         = 'crc_customer';
    public const ROLE_SECRETARY        = 'crc_secretary';
    public const ROLE_MEDIA_SUPERVISOR = 'crc_media_supervisor';
    public const ROLE_MEDIA_MANAGER    = 'crc_media_manager';
    public const ROLE_ACCOUNTANT       = 'crc_accountant';
    public const ROLE_DESIGNER         = 'crc_designer';
    public const ROLE_INSTALLER        = 'crc_installer';
    public const ROLE_MANAGER          = 'crc_manager';

    /** @return array<string,array{label:string,group:string}> cap => meta */
    public static function registry(): array
    {
        return [
            self::ORDERS_VIEW       => ['label' => 'مشاهدهٔ سفارش‌ها',            'group' => 'سفارش'],
            self::ORDERS_CREATE_FOR => ['label' => 'ثبت سفارش به‌جای مشتری',      'group' => 'سفارش'],
            self::ORDERS_EDIT       => ['label' => 'ویرایش سفارش',                'group' => 'سفارش'],
            self::ORDERS_CANCEL     => ['label' => 'لغو سفارش',                   'group' => 'سفارش'],
            self::ORDERS_REFUND     => ['label' => 'بازگشت وجه دستی',             'group' => 'سفارش'],
            self::INVOICES_VIEW     => ['label' => 'مشاهدهٔ فاکتورها',            'group' => 'فاکتور'],
            self::INVOICES_ISSUE    => ['label' => 'صدور فاکتور',                 'group' => 'فاکتور'],
            self::INVOICES_APPROVE  => ['label' => 'تأیید فاکتور',                'group' => 'فاکتور'],
            self::INVOICES_CORRECT  => ['label' => 'فاکتور اصلاحی',              'group' => 'فاکتور'],
            self::PAYMENTS_VIEW     => ['label' => 'مشاهدهٔ پرداخت‌ها',           'group' => 'پرداخت'],
            self::PAYMENTS_RECORD   => ['label' => 'ثبت دستی پرداخت',             'group' => 'پرداخت'],
            self::CHEQUES_APPROVE   => ['label' => 'تأیید چک',                    'group' => 'پرداخت'],
            self::CATALOG_VIEW      => ['label' => 'مشاهدهٔ کاتالوگ',             'group' => 'کاتالوگ'],
            self::CATALOG_EDIT      => ['label' => 'ویرایش محصول و قیمت',         'group' => 'کاتالوگ'],
            self::DISCOUNTS_MANAGE  => ['label' => 'مدیریت کد تخفیف',             'group' => 'کاتالوگ'],
            self::CUSTOMERS_VIEW    => ['label' => 'مشاهدهٔ مشتریان',             'group' => 'مشتری'],
            self::CUSTOMERS_EDIT    => ['label' => 'ویرایش مشتریان',              'group' => 'مشتری'],
            self::MEDIA_VIEW        => ['label' => 'مشاهدهٔ رسانه‌ها',            'group' => 'رسانه'],
            self::MEDIA_EDIT        => ['label' => 'ورود و ویرایش رسانه',         'group' => 'رسانه'],
            self::TASKS_DESIGN      => ['label' => 'کارهای طراحی',                'group' => 'عملیات'],
            self::TASKS_INSTALL     => ['label' => 'کارهای نصب',                  'group' => 'عملیات'],
            self::SMS_SEND          => ['label' => 'ارسال پیامک',                 'group' => 'تنظیمات'],
            self::SMS_SETTINGS      => ['label' => 'تنظیمات پیامک',               'group' => 'تنظیمات'],
            self::GATEWAY_SETTINGS  => ['label' => 'تنظیمات درگاه پرداخت',        'group' => 'تنظیمات'],
            self::REPORTS_VIEW      => ['label' => 'مشاهدهٔ گزارش‌ها',            'group' => 'گزارش'],
            self::AUDIT_VIEW        => ['label' => 'مشاهدهٔ گزارش تغییرات',       'group' => 'گزارش'],
            self::ROLES_VIEW        => ['label' => 'مشاهدهٔ ماتریس دسترسی',       'group' => 'دسترسی'],
            self::ROLES_MANAGE      => ['label' => 'تغییر ماتریس دسترسی',         'group' => 'دسترسی'],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::registry());
    }

    /** @return array<string,string> role slug => Persian label (roles managed by this plugin) */
    public static function managedRoles(): array
    {
        return [
            self::ROLE_CUSTOMER         => 'مشتری',
            self::ROLE_SECRETARY        => 'منشی',
            self::ROLE_MEDIA_SUPERVISOR => 'سرپرست رسانه‌های محیطی',
            self::ROLE_MEDIA_MANAGER    => 'مدیر رسانه',
            self::ROLE_ACCOUNTANT       => 'حسابدار',
            self::ROLE_DESIGNER         => 'طراح',
            self::ROLE_INSTALLER        => 'نصاب',
            self::ROLE_MANAGER          => 'مدیر',
        ];
    }

    /**
     * Default matrix (seeds new roles only; live changes are made from the panel).
     * @return array<string,list<string>> role => caps
     */
    public static function defaultMatrix(): array
    {
        $manager = array_values(array_diff(self::all(), [self::GATEWAY_SETTINGS]));
        $manager[] = self::GATEWAY_SETTINGS; // manager sees everything by default; admin may revoke.

        return [
            self::ROLE_CUSTOMER => [],
            self::ROLE_SECRETARY => [
                self::ORDERS_VIEW, self::ORDERS_CREATE_FOR, self::INVOICES_VIEW,
                self::CATALOG_VIEW, self::CUSTOMERS_VIEW, self::CUSTOMERS_EDIT, self::MEDIA_VIEW,
            ],
            self::ROLE_MEDIA_SUPERVISOR => [
                self::MEDIA_VIEW, self::MEDIA_EDIT, self::CATALOG_VIEW,
            ],
            self::ROLE_MEDIA_MANAGER => [
                self::MEDIA_VIEW, self::MEDIA_EDIT, self::ORDERS_VIEW,
                self::CUSTOMERS_VIEW, self::CATALOG_VIEW, self::REPORTS_VIEW,
            ],
            self::ROLE_ACCOUNTANT => [
                self::ORDERS_VIEW, self::ORDERS_CREATE_FOR, self::ORDERS_EDIT,
                self::INVOICES_VIEW, self::INVOICES_ISSUE, self::INVOICES_APPROVE, self::INVOICES_CORRECT,
                self::PAYMENTS_VIEW, self::PAYMENTS_RECORD, self::CHEQUES_APPROVE,
                self::DISCOUNTS_MANAGE, self::CUSTOMERS_VIEW, self::REPORTS_VIEW, self::AUDIT_VIEW,
            ],
            self::ROLE_DESIGNER  => [self::TASKS_DESIGN, self::ORDERS_VIEW],
            self::ROLE_INSTALLER => [self::TASKS_INSTALL],
            self::ROLE_MANAGER   => array_values(array_unique($manager)),
        ];
    }

    public static function isCapability(string $cap): bool
    {
        return isset(self::registry()[$cap]);
    }

    public static function isManagedRole(string $role): bool
    {
        return isset(self::managedRoles()[$role]);
    }
}
