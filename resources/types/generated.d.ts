declare namespace App.Domains.Booking.Application.Data {
export type AdminBookingDetailData = {
id: string;
booking_number: string;
status: App.Domains.Booking.Domain.Enums.BookingStatus;
kind: App.Domains.Booking.Domain.Enums.BookingKind;
kind_label: string;
client_name: string | null;
phone: string | null;
user_name: string | null;
user_email: string | null;
from_location: string;
to_location: string;
from_lat: number | null;
from_lng: number | null;
to_lat: number | null;
to_lng: number | null;
distance: number | null;
pickup_at: string;
return_time: string | null;
round_trip: boolean;
is_return: boolean;
days: number;
passengers: number;
week_days: App.Domains.Booking.Domain.Enums.WeekDays | null;
special_requests: string | null;
tourist_circuit_name: string | null;
base_price: number;
total_price: number;
discount: number;
promo_code: string | null;
commission: number | null;
driver_earning: number | null;
commission_preview: number;
driver_earning_preview: number;
driver_id: string | null;
driver_name: string | null;
driver_phone: string | null;
subscription_driver_name: string | null;
is_recurring: boolean;
parent_booking_id: string | null;
parent_booking_number: string | null;
remaining_days: number;
subscription_end_date: string | null;
child_bookings_count: number;
is_revoked: boolean;
revoked_at: string | null;
started_at: string | null;
completed_at: string | null;
cancelled_at: string | null;
cancellation_reason: string | null;
created_at: string;
updated_at: string;
can_be_cancelled: boolean;
allowed_statuses: Array<App.Domains.Booking.Domain.Enums.BookingStatus>;
can_assign_driver: boolean;
can_remove_driver: boolean;
can_delete: boolean;
can_reopen: boolean;
can_edit: boolean;
};
export type AdminBookingListItemData = {
id: string;
booking_number: string;
kind: App.Domains.Booking.Domain.Enums.BookingKind;
kind_label: string;
client_name: string | null;
phone: string | null;
from_location: string;
to_location: string;
pickup_at: string;
base_price: number;
status: App.Domains.Booking.Domain.Enums.BookingStatus;
driver_id: string | null;
driver_name: string | null;
round_trip: boolean;
is_return: boolean;
is_revoked: boolean;
days: number;
created_at: string;
can_assign_driver: boolean;
can_remove_driver: boolean;
can_edit: boolean;
};
export type AdminBookingPageData = {
data: Array<App.Domains.Booking.Application.Data.AdminBookingListItemData>;
current_page: number;
last_page: number;
per_page: number;
total: number;
};
export type AdminDashboardData = {
total_bookings: number;
pending_bookings: number;
total_drivers: number;
active_drivers: number;
total_revenue: number;
completed_today: number;
in_progress_today: number;
cancelled_today: number;
recent_pending: Array<App.Domains.Booking.Application.Data.AdminRecentBookingData>;
top_drivers: Array<App.Domains.Booking.Application.Data.DriverRevenueData>;
};
export type AdminRecentBookingData = {
id: string;
booking_number: string;
client_name: string | null;
phone: string | null;
from_location: string;
to_location: string;
pickup_at: string;
total_price: number;
driver_name: string | null;
created_at: string;
};
export type AssignDriverData = {
driver_id: string;
};
export type AssignableDriverData = {
id: string;
user_id: string;
name: string | null;
phone: string | null;
active_bookings_count: number;
};
export type AssignedBookingData = {
id: string;
booking_number: string;
status: 'confirmed' | 'in_progress';
trip_type: App.Domains.Booking.Domain.Enums.TripType;
round_trip: boolean;
from_location: string;
to_location: string;
pickup_at: string;
return_time: string | null;
is_simple_return: boolean;
is_subscription_parent: boolean;
is_subscription_child: boolean;
is_revoked: boolean;
subscription_label: string;
subscription_end_date: string | null;
week_days: App.Domains.Booking.Domain.Enums.WeekDays | null;
days: number | null;
remaining_days: number | null;
parent_client_name: string | null;
client_name: string | null;
phone: string | null;
special_requests: string | null;
base_price: number;
distance: number | null;
subscription_index: number | null;
started_at: string | null;
can_be_cancelled: boolean;
};
export type AvailableBookingData = {
id: string;
trip_type: App.Domains.Booking.Domain.Enums.TripType;
round_trip: boolean;
from_location: string;
to_location: string;
pickup_at: string;
return_time: string | null;
is_simple_return: boolean;
is_subscription_parent: boolean;
is_subscription_child: boolean;
is_revoked: boolean;
subscription_label: string;
subscription_end_date: string | null;
week_days: App.Domains.Booking.Domain.Enums.WeekDays | null;
days: number | null;
remaining_days: number | null;
parent_client_name: string | null;
parent_booking_number: string | null;
parent_pickup_at: string | null;
can_be_revoked: boolean;
};
export type BookingConfirmationData = {
booking_number: string;
status: App.Domains.Booking.Domain.Enums.BookingStatus;
status_label: string;
from_location: string;
to_location: string;
pickup_date: string;
pickup_time: string;
round_trip: boolean;
return_time: string | null;
is_recurring: boolean;
days: number;
total_price: number;
};
export type BookingHistoryData = {
id: string;
booking_number: string;
status: 'completed' | 'cancelled';
from_location: string;
to_location: string;
pickup_at: string;
passengers: number;
remaining_days: number | null;
started_at: string | null;
completed_at: string | null;
cancelled_at: string | null;
cancellation_reason: string | null;
duration_seconds: number | null;
total_price: number;
commission: number;
driver_earning: number;
};
export type BookingHistoryPageData = {
data: Array<App.Domains.Booking.Application.Data.BookingHistoryData>;
current_page: number;
last_page: number;
per_page: number;
total: number;
};
export type CalculatePriceData = {
from_lng: number;
from_lat: number;
to_lng: number;
to_lat: number;
pickup_time: string | null;
return_time: string | null;
round_trip: boolean;
days: number;
};
export type CancelBookingData = {
cancellation_reason: string;
};
export type ChangeBookingStatusData = {
status: App.Domains.Booking.Domain.Enums.BookingStatus;
cancellation_reason: string | null;
};
export type CreateAdminBookingData = {
client_name: string | null;
phone: string;
from_location: string;
to_location: string;
from_lat: number;
from_lng: number;
to_lat: number;
to_lng: number;
pickup_date: string;
pickup_time: string;
base_price: number;
days: number;
round_trip: boolean;
return_time: string | null;
week_days: App.Domains.Booking.Domain.Enums.WeekDays | null;
special_requests: string | null;
};
export type CreatePublicBookingData = {
from_location: string;
to_location: string;
from_lat: number;
from_lng: number;
to_lat: number;
to_lng: number;
pickup_date: string;
pickup_time: string;
phone: string;
days: number;
round_trip: boolean;
return_time: string | null;
week_days: App.Domains.Booking.Domain.Enums.WeekDays | null;
special_requests: string | null;
promo_code: string | null;
};
export type DriverDashboardData = {
total_trips: number;
rating: number;
confirmed_trips: number;
completed_trips: number;
cancelled_trips: number;
earnings_today: number;
total_earnings: number;
commission_today: number;
total_commission: number;
total_duration_minutes: number;
recent_available: Array<App.Domains.Booking.Application.Data.AvailableBookingData>;
recent_assigned: Array<App.Domains.Booking.Application.Data.AssignedBookingData>;
};
export type DriverRevenueData = {
id: string;
name: string | null;
earnings: number;
commission_due: number;
};
export type PriceQuoteData = {
distance_km: number;
base_price: number;
go_price: number;
return_price: number | null;
trip_price: number;
days: number;
total_price: number;
surcharge_amount: number;
surcharge_free_window: string;
};
export type UpdateAdminBookingData = {
client_name: string | null;
phone: string;
from_location: string;
to_location: string;
from_lat: number;
from_lng: number;
to_lat: number;
to_lng: number;
pickup_date: string;
pickup_time: string;
base_price: number;
days: number;
round_trip: boolean;
return_time: string | null;
week_days: App.Domains.Booking.Domain.Enums.WeekDays | null;
special_requests: string | null;
};
}
declare namespace App.Domains.Booking.Domain.Enums {
export type BookingKind = 'single' | 'subscription_parent' | 'subscription_child' | 'simple_return';
export type BookingStatus = 'pending' | 'confirmed' | 'in_progress' | 'completed' | 'cancelled' | 'expired' | 'missed';
export type TripType = 'go' | 'return';
export type WeekDays = 'lun_ven' | 'lun_sam' | 'lun_dim';
}
declare namespace App.Domains.Finance.Application.Data {
export type MonthlyPayoutData = {
month: string;
is_current: boolean;
validated_amount: number;
pending_amount: number;
cancelled_amount: number;
total_charges: number;
fixed_amount: number;
worked_days: number;
agent_leave_days: number;
immobilization_days: number;
};
}
declare namespace App.Domains.Finance.Domain.Enums {
export type PaymentStatus = 'pending' | 'completed' | 'cancelled' | 'failed';
export type PaymentType = 'commission' | 'contract' | 'bonus' | 'other' | 'subscription_revenue';
}
declare namespace App.Domains.Fleet.Application.Data {
export type ActivePauseData = {
start_date: string;
reason_type: App.Domains.Fleet.Domain.Enums.VehiclePauseReason;
reason_label: string;
reason_notes: string | null;
is_auto: boolean;
};
export type AdminAvailableVehicleData = {
id: string;
vehicle_number: string;
vehicle_type: 'moto' | 'tricycle' | 'car';
owner_id: string | null;
owner_name: string | null;
};
export type AdminOwnerDetailData = {
id: string;
name: string;
email: string | null;
phone: string;
adresse: string | null;
is_active: boolean;
vehicles: Array<App.Domains.Fleet.Application.Data.AdminOwnerVehicleData>;
created_at: string;
};
export type AdminOwnerListItemData = {
id: string;
name: string;
email: string | null;
phone: string;
adresse: string | null;
is_active: boolean;
vehicles: Array<App.Domains.Fleet.Application.Data.AdminOwnerVehicleBadgeData>;
created_at: string;
};
export type AdminOwnerPageData = {
owners: Array<App.Domains.Fleet.Application.Data.AdminOwnerListItemData>;
stats: App.Domains.Fleet.Application.Data.AdminOwnerStatsData;
};
export type AdminOwnerStatsData = {
total: number;
active: number;
inactive: number;
};
export type AdminOwnerVehicleBadgeData = {
id: string;
vehicle_number: string;
};
export type AdminOwnerVehicleContractData = {
id: string;
contract_months: number;
total_amount: number;
total_paid: number;
start_date: string | null;
end_date: string | null;
unlimited_internet: number;
spotify_premium: number;
manager_remuneration: number;
notes: string | null;
};
export type AdminOwnerVehicleData = {
id: string;
vehicle_number: string;
vehicle_type: 'moto' | 'tricycle' | 'car';
notes: string | null;
is_active: boolean;
has_driver: boolean;
driver_name: string | null;
contract: App.Domains.Fleet.Application.Data.AdminOwnerVehicleContractData | null;
};
export type AdminVehicleActiveContractData = {
id: string;
total_amount: number;
monthly_payment: number;
total_paid: number;
remaining_amount: number;
surplus: number;
progress_percentage: number;
start_date: string | null;
end_date: string | null;
recent_payments: Array<App.Domains.Fleet.Application.Data.AdminVehiclePaymentData>;
payments_count: number;
};
export type AdminVehicleContractDetailData = {
contract: App.Domains.Fleet.Application.Data.AdminVehicleContractListItemData;
payments_count: number;
payments_by_month: Array<App.Domains.Fleet.Application.Data.AdminVehicleContractMonthData>;
driver_contracts: Array<App.Domains.Fleet.Application.Data.AdminVehicleContractDriverData>;
pauses: Array<App.Domains.Fleet.Application.Data.VehiclePauseData>;
current_driver: App.Domains.Fleet.Application.Data.AdminVehicleContractPartyData | null;
current_driver_since: string | null;
};
export type AdminVehicleContractDriverData = {
id: string;
driver_id: string | null;
driver_name: string | null;
start_date: string | null;
end_date: string | null;
contract_months: number;
status: App.Domains.Workforce.Domain.Enums.DriverContractStatus;
payments_count: number;
end_reason: string | null;
};
export type AdminVehicleContractListItemData = {
id: string;
status: App.Domains.Fleet.Domain.Enums.VehicleContractStatus;
owner: App.Domains.Fleet.Application.Data.AdminVehicleContractPartyData | null;
vehicle: App.Domains.Fleet.Application.Data.AdminVehicleContractVehicleData | null;
contract_months: number | null;
start_date: string | null;
end_date: string | null;
total_amount: number;
total_paid: number;
remaining: number;
surplus: number;
progress_percent: number;
unlimited_internet: number | null;
spotify_premium: number | null;
manager_remuneration: number | null;
notes: string | null;
is_editable: boolean;
is_deletable: boolean;
created_at: string;
};
export type AdminVehicleContractMonthData = {
month: string;
total: number;
};
export type AdminVehicleContractPageData = {
contracts: Array<App.Domains.Fleet.Application.Data.AdminVehicleContractListItemData>;
available_vehicles: Array<App.Domains.Fleet.Application.Data.AdminAvailableVehicleData>;
};
export type AdminVehicleContractPartyData = {
id: string;
name: string | null;
phone: string | null;
email: string | null;
};
export type AdminVehicleContractProgressData = {
total_paid: number;
total_amount: number;
progress_percentage: number;
};
export type AdminVehicleContractVehicleData = {
id: string;
vehicle_number: string;
vehicle_type: 'moto' | 'tricycle' | 'car';
is_active: boolean;
notes: string | null;
};
export type AdminVehicleCurrentDriverData = {
driver_id: string;
name: string | null;
phone: string | null;
start_date: string | null;
contract_months: number;
accrued_leave_days: number;
used_leave_days: number;
};
export type AdminVehicleDetailData = {
id: string;
vehicle_number: string;
vehicle_type: 'moto' | 'tricycle' | 'car';
notes: string | null;
is_active: boolean;
active_pause: App.Domains.Fleet.Application.Data.VehiclePauseData | null;
contract: App.Domains.Fleet.Application.Data.AdminVehicleActiveContractData | null;
past_contracts: Array<App.Domains.Fleet.Application.Data.AdminVehiclePastContractData>;
driver_history: Array<App.Domains.Fleet.Application.Data.AdminVehicleDriverContractData>;
owner: App.Domains.Fleet.Application.Data.AdminVehicleOwnerData | null;
current_driver: App.Domains.Fleet.Application.Data.AdminVehicleCurrentDriverData | null;
pauses: Array<App.Domains.Fleet.Application.Data.VehiclePauseData>;
};
export type AdminVehicleDriverContractData = {
id: string;
driver_id: string | null;
driver_name: string | null;
start_date: string | null;
end_date: string | null;
contract_months: number;
is_active: boolean;
end_reason: string | null;
};
export type AdminVehicleListItemData = {
id: string;
vehicle_number: string;
vehicle_type: 'moto' | 'tricycle' | 'car';
notes: string | null;
is_active: boolean;
is_on_pause: boolean;
active_pause_id: string | null;
owner: App.Domains.Fleet.Application.Data.AdminVehiclePersonData | null;
driver: App.Domains.Fleet.Application.Data.AdminVehiclePersonData | null;
contract: App.Domains.Fleet.Application.Data.AdminVehicleContractProgressData | null;
created_at: string;
};
export type AdminVehicleOwnerData = {
id: string;
name: string;
phone: string | null;
email: string | null;
};
export type AdminVehiclePageData = {
vehicles: Array<App.Domains.Fleet.Application.Data.AdminVehicleListItemData>;
stats: App.Domains.Fleet.Application.Data.AdminVehicleStatsData;
owners: Array<App.Domains.Fleet.Application.Data.AdminVehiclePersonData>;
};
export type AdminVehiclePastContractData = {
id: string;
total_amount: number;
total_paid: number;
start_date: string | null;
end_date: string | null;
status: App.Domains.Fleet.Domain.Enums.VehicleContractStatus;
};
export type AdminVehiclePaymentData = {
id: string;
amount: number;
payment_date: string | null;
payment_method: string | null;
reference_number: string | null;
};
export type AdminVehiclePersonData = {
id: string;
name: string | null;
phone: string | null;
};
export type AdminVehicleStatsData = {
total: number;
active: number;
paused: number;
without_contract: number;
};
export type ContractDurationData = {
months: number;
total_amount: number;
};
export type CreateOwnerData = {
name: string;
email: string | null;
phone: string;
password: string;
adresse: string | null;
is_active: boolean;
vehicle: App.Domains.Fleet.Application.Data.OwnerVehicleAttachmentData | null;
confirm_transfer: boolean;
};
export type CreateVehicleContractData = {
vehicle_id: string;
contract_months: number;
total_amount: number;
start_date: string;
unlimited_internet: number | null;
spotify_premium: number | null;
manager_remuneration: number | null;
notes: string | null;
};
export type CreateVehiclePauseData = {
start_date: string;
end_date: string | null;
reason_type: App.Domains.Fleet.Domain.Enums.VehiclePauseReason;
reason_notes: string | null;
};
export type EndVehiclePauseData = {
end_date: string;
};
export type OwnerContractDetailData = {
contract_months: number;
start_date: string;
planned_end_date: string | null;
extended_end_date: string | null;
total_amount: number;
total_paid: number;
remaining_amount: number;
daily_net_amount: number;
progress_percentage: number;
months_elapsed: number;
months_remaining: number;
total_charges: number;
unlimited_internet: number;
spotify_premium: number;
manager_remuneration: number;
total_contract_days: number;
total_pause_days_taken: number;
remaining_contract_days: number;
pause_usage_percentage: number;
};
export type OwnerContractPauseSummaryData = {
total_contract_days: number;
total_pause_days_taken: number;
remaining_contract_days: number;
pause_usage_percentage: number;
};
export type OwnerContractSummaryData = {
contract_months: number;
months_elapsed: number;
months_remaining: number;
progress_percentage: number;
remaining_amount: number;
};
export type OwnerVehicleAttachmentData = {
mode: 'existing' | 'new';
vehicle_id: string | null;
vehicle_number: string | null;
vehicle_type: 'moto' | 'tricycle' | 'car' | null;
notes: string | null;
contract: App.Domains.Fleet.Application.Data.VehicleContractInputData | null;
};
export type OwnerVehicleDetailData = {
id: string;
vehicle_number: string;
vehicle_type: string | null;
active_pause: App.Domains.Fleet.Application.Data.ActivePauseData | null;
contract: App.Domains.Fleet.Application.Data.OwnerContractDetailData | null;
};
export type OwnerVehicleEditData = {
id: string;
vehicle_number: string;
vehicle_type: 'moto' | 'tricycle' | 'car';
notes: string | null;
contract: App.Domains.Fleet.Application.Data.VehicleContractInputData | null;
};
export type OwnerVehiclePausesData = {
summary: App.Domains.Fleet.Application.Data.OwnerContractPauseSummaryData | null;
items: Array<App.Domains.Fleet.Application.Data.VehiclePauseData>;
};
export type OwnerVehicleSummaryData = {
id: string;
vehicle_number: string;
vehicle_type: string | null;
is_on_pause: boolean;
contract: App.Domains.Fleet.Application.Data.OwnerContractSummaryData | null;
};
export type SaveVehicleData = {
vehicle_number: string;
vehicle_type: 'moto' | 'tricycle' | 'car';
notes: string | null;
};
export type SetOwnerStatusData = {
is_active: boolean;
};
export type SetVehicleStatusData = {
is_active: boolean;
};
export type UpdateOwnerData = {
name: string;
email: string | null;
phone: string;
adresse: string | null;
is_active: boolean;
vehicles: Array<App.Domains.Fleet.Application.Data.OwnerVehicleEditData>;
add_vehicle: App.Domains.Fleet.Application.Data.OwnerVehicleAttachmentData | null;
confirm_transfer: boolean;
};
export type UpdateOwnerPasswordData = {
password: string;
};
export type UpdateVehicleContractData = {
contract_months: number;
total_amount: number;
start_date: string;
status: App.Domains.Fleet.Domain.Enums.VehicleContractStatus;
vehicle_id: string | null;
unlimited_internet: number | null;
spotify_premium: number | null;
manager_remuneration: number | null;
notes: string | null;
};
export type VehicleContractDefaultsData = {
durations: Array<App.Domains.Fleet.Application.Data.ContractDurationData>;
unlimited_internet: number;
spotify_premium: number;
manager_remuneration: number;
};
export type VehicleContractInputData = {
contract_months: number;
total_amount: number;
start_date: string;
unlimited_internet: number | null;
spotify_premium: number | null;
manager_remuneration: number | null;
notes: string | null;
};
export type VehiclePauseData = {
id: string;
start_date: string;
end_date: string | null;
reason_type: App.Domains.Fleet.Domain.Enums.VehiclePauseReason;
reason_label: string;
reason_notes: string | null;
is_auto: boolean;
days_count: number | null;
};
}
declare namespace App.Domains.Fleet.Domain.Enums {
export type VehicleContractStatus = 'active' | 'completed' | 'cancelled';
export type VehiclePauseReason = 'agent_leave' | 'agent_change' | 'technical' | 'accident' | 'legal' | 'other';
}
declare namespace App.Domains.Identity.Application.Data {
export type ChangePasswordData = {
current_password: string;
password: string;
};
export type ForgotPasswordData = {
email: string;
};
export type LoginData = {
email: string;
password: string;
profil: App.Domains.Identity.Domain.Enums.Profil | null;
};
export type ResetPasswordData = {
token: string;
password: string;
};
export type UpdateProfileData = {
name: string;
phone: string;
adresse: string | null;
};
export type UserData = {
id: string;
name: string | null;
email: string | null;
phone: string | null;
adresse: string | null;
profil: App.Domains.Identity.Domain.Enums.Profil;
dashboard_path: string;
roles: Array<string>;
permissions: Array<string>;
};
}
declare namespace App.Domains.Identity.Domain.Enums {
export type Profil = 'admin' | 'client' | 'driver' | 'owner';
}
declare namespace App.Domains.Notification.Application.Data {
export type DeviceTokenData = {
token: string;
};
export type NotificationData = {
id: number;
title: string;
message: string;
type: 'info' | 'success' | 'warning' | 'error';
is_read: boolean;
data: Record<string, unknown> | null;
created_at: string;
};
export type NotificationPageData = {
data: Array<App.Domains.Notification.Application.Data.NotificationData>;
unread_count: number;
current_page: number;
last_page: number;
per_page: number;
total: number;
};
export type NotificationPreferencesData = {
push_notifications: boolean;
email_notifications: boolean;
};
export type UpdatePreferencesData = {
push_notifications?: boolean;
email_notifications?: boolean;
};
}
declare namespace App.Domains.Workforce.Application.Data {
export type AdminDriverActiveContractData = {
id: string;
vehicle_id: string;
vehicle_number: string;
vehicle_type: string | null;
vehicle_color: string | null;
owner_name: string | null;
owner_phone: string | null;
contract_months: number;
start_date: string;
months_elapsed: number;
editable: boolean;
};
export type AdminDriverBookingStatsData = {
total: number;
completed: number;
cancelled: number;
confirmed: number;
in_progress: number;
total_minutes: number;
average_rating: number;
};
export type AdminDriverCommissionStatsData = {
driver_earning: number;
unpaid_revenue: number;
paid_revenue: number;
};
export type AdminDriverContractDetailData = {
contract: App.Domains.Workforce.Application.Data.AdminDriverContractListItemData;
payments_count: number;
total_paid: number;
payments_by_month: Array<App.Domains.Fleet.Application.Data.AdminVehicleContractMonthData>;
pauses: Array<App.Domains.Fleet.Application.Data.VehiclePauseData>;
vehicle_color: string | null;
owner: App.Domains.Fleet.Application.Data.AdminVehicleContractPartyData | null;
vehicle_contract_id: string | null;
vehicle_contract_status: App.Domains.Fleet.Domain.Enums.VehicleContractStatus | null;
vehicle_contract_months: number | null;
vehicle_contract_notes: string | null;
};
export type AdminDriverContractListItemData = {
id: string;
status: App.Domains.Workforce.Domain.Enums.DriverContractStatus;
driver: App.Domains.Fleet.Application.Data.AdminVehicleContractPartyData | null;
driver_is_active: boolean;
vehicle: App.Domains.Fleet.Application.Data.AdminVehicleContractVehicleData | null;
start_date: string | null;
end_date: string | null;
contract_months: number;
months_elapsed: number;
accrued_leave_days: number;
used_leave_days: number;
available_leave_days: number;
remaining_leave_days: number;
end_reason: App.Domains.Workforce.Domain.Enums.DriverContractEndReason | null;
end_reason_label: string | null;
end_notes: string | null;
is_editable: boolean;
is_deletable: boolean;
created_at: string;
};
export type AdminDriverContractPageData = {
contracts: Array<App.Domains.Workforce.Application.Data.AdminDriverContractListItemData>;
};
export type AdminDriverDetailData = {
id: string;
user_id: string;
name: string | null;
email: string | null;
phone: string | null;
adresse: string | null;
is_active: boolean;
is_available: boolean;
agent_code: string | null;
agent_id: string | null;
license_number: string | null;
created_at: string;
booking_stats: App.Domains.Workforce.Application.Data.AdminDriverBookingStatsData;
commission_stats: App.Domains.Workforce.Application.Data.AdminDriverCommissionStatsData;
subscription_revenue: App.Domains.Workforce.Application.Data.AdminDriverSubscriptionRevenueData;
active_contract: App.Domains.Workforce.Application.Data.AdminDriverActiveContractData | null;
recent_bookings: Array<App.Domains.Workforce.Application.Data.AdminDriverRecentBookingData>;
};
export type AdminDriverLeaveDetailData = {
id: string;
user_id: string;
name: string | null;
email: string | null;
phone: string | null;
has_active_contract: boolean;
contract_start: string | null;
contract_months: number | null;
leave_days_per_month: number;
total_leave_days: number;
leave_days_used: number;
available_leave_days: number;
remaining_leave_days: number;
pending: Array<App.Domains.Workforce.Application.Data.LeaveRequestData>;
ongoing: App.Domains.Workforce.Application.Data.LeaveRequestData | null;
history: Array<App.Domains.Workforce.Application.Data.LeaveRequestData>;
};
export type AdminDriverLeaveSummaryData = {
id: string;
user_id: string;
name: string | null;
has_active_contract: boolean;
contract_months: number | null;
leave_days_per_month: number;
total_leave_days: number;
leave_days_used: number;
available_leave_days: number;
remaining_leave_days: number;
is_on_leave: boolean;
ongoing_since: string | null;
pending_requests: number;
};
export type AdminDriverListItemData = {
id: string;
user_id: string;
name: string | null;
email: string | null;
phone: string | null;
license_number: string | null;
is_active: boolean;
is_available: boolean;
vehicle_number: string | null;
vehicle_type: string | null;
total_trips: number;
created_at: string;
};
export type AdminDriverPageData = {
drivers: Array<App.Domains.Workforce.Application.Data.AdminDriverListItemData>;
stats: App.Domains.Workforce.Application.Data.AdminDriverStatsData;
};
export type AdminDriverRecentBookingData = {
id: string;
status: App.Domains.Booking.Domain.Enums.BookingStatus;
from_location: string;
to_location: string;
pickup_at: string;
driver_earning: number;
commission: number;
};
export type AdminDriverStatsData = {
total: number;
active: number;
inactive: number;
available: number;
};
export type AdminDriverSubscriptionRevenueData = {
total_due: number;
total_paid: number;
balance_due: number;
subscriptions: Array<App.Domains.Workforce.Application.Data.AdminDriverSubscriptionRevenueItemData>;
};
export type AdminDriverSubscriptionRevenueItemData = {
subscription_id: string;
booking_number: string;
bookings_count: number;
amount: number;
};
export type AdminLeaveRequestData = {
id: string;
driver_id: string;
driver_name: string | null;
start_date: string;
requested_days: number;
expected_end_date: string | null;
available_leave_days: number;
has_active_contract: boolean;
requested_at: string;
};
export type AdminOwnerOptionData = {
id: string;
name: string | null;
phone: string | null;
vehicles: Array<App.Domains.Workforce.Application.Data.AdminOwnerVehicleOptionData>;
};
export type AdminOwnerRenewalOptionData = {
id: string;
name: string | null;
phone: string | null;
vehicles: Array<App.Domains.Workforce.Application.Data.AdminOwnerRenewalVehicleOptionData>;
};
export type AdminOwnerRenewalVehicleOptionData = {
id: string;
vehicle_number: string;
vehicle_type: string | null;
color: string | null;
total_months: number;
months_used: number;
remaining_months: number;
suggested_start_date: string;
vehicle_contract_id: string;
};
export type AdminOwnerVehicleOptionData = {
id: string;
vehicle_number: string;
vehicle_type: string | null;
color: string | null;
contract_months: number | null;
contract_start_date: string | null;
};
export type CreateDriverData = {
name: string;
email: string | null;
phone: string;
password: string;
adresse: string | null;
license_number: string;
agent_code: string | null;
agent_id: string | null;
contract_mode: 'new' | 'renewal';
owner_id: string | null;
vehicle_id: string | null;
contract_months: number | null;
start_date: string | null;
renewal_agent_code: string | null;
renewal_agent_id: string | null;
renewal_owner_id: string | null;
renewal_vehicle_id: string | null;
renewal_contract_months: number | null;
renewal_start_date: string | null;
};
export type DriverLeavesData = {
leave_days_per_month: number;
total_leave_days: number;
leave_days_used: number;
available_leave_days: number;
remaining_leave_days: number;
pending: Array<App.Domains.Workforce.Application.Data.LeaveRequestData>;
ongoing: App.Domains.Workforce.Application.Data.LeaveRequestData | null;
history: Array<App.Domains.Workforce.Application.Data.LeaveRequestData>;
rejected: Array<App.Domains.Workforce.Application.Data.LeaveRequestData>;
can_request: boolean;
};
export type EndDriverContractData = {
end_date: string;
end_reason: 'demission' | 'abandon' | 'fin_contrat' | 'autre';
end_notes: string | null;
};
export type EndLeaveData = {
end_date: string;
};
export type LeavePeriodData = {
start_date: string;
requested_days: number;
};
export type LeaveRequestData = {
id: string;
status: App.Domains.Workforce.Domain.Enums.LeaveStatus;
start_date: string;
end_date: string | null;
requested_days: number;
effective_days: number | null;
expected_end_date: string | null;
is_overdue: boolean;
is_historical: boolean;
rejection_reason: string | null;
requested_at: string;
};
export type RejectLeaveData = {
rejection_reason: string;
};
export type RequestLeaveData = {
start_date: string;
requested_days: number;
};
export type ToggleDriverAvailabilityData = {
is_available: boolean;
};
export type ToggleDriverStatusData = {
is_active: boolean;
};
export type UpdateDriverContractData = {
start_date: string;
contract_months: number;
vehicle_id: string | null;
};
export type UpdateDriverData = {
name: string;
email: string | null;
phone: string;
is_active: boolean | null;
adresse: string | null;
license_number: string;
is_available: boolean | null;
agent_code: string | null;
agent_id: string | null;
owner_mode: 'existing' | 'renewal';
owner_id: string | null;
vehicle_id: string | null;
existing_contract_months: number | null;
existing_start_date: string | null;
renewal_agent_code: string | null;
renewal_agent_id: string | null;
renewal_owner_id: string | null;
renewal_vehicle_id: string | null;
renewal_contract_months: number | null;
renewal_start_date: string | null;
};
export type UpdateDriverPasswordData = {
password: string;
};
}
declare namespace App.Domains.Workforce.Domain.Enums {
export type DriverContractEndReason = 'demission' | 'abandon' | 'fin_contrat' | 'autre' | 'new_contract';
export type DriverContractStatus = 'active' | 'ended';
export type LeaveStatus = 'pending' | 'ongoing' | 'completed' | 'rejected';
}
declare namespace App.Shared.Data {
export type BaseData = {
};
}
