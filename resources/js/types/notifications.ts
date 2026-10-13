export type NotificationItem = {
    id: string;
    category: string;
    title: string;
    message: string;
    action_label: string;
    action_path: string | null;
    occurred_at: string;
    read_at: string | null;
};
export type NotificationPreference = {
    group: string;
    label: string;
    in_app_enabled: boolean;
    email_enabled: boolean;
    mandatory_in_app: boolean;
};
