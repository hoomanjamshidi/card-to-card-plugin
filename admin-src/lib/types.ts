/** Shapes shared by the panel. */

export type CardSkin = 'bank' | 'custom' | 'glass' | 'mono';

export type Card = {
	id: string;
	number: string;
	holder: string;
	sheba: string;
	account: string;
	bank: string;
	skin: CardSkin;
	color1: string;
	color2: string;
	active: boolean;
};

export type ReceiptField = {
	id: string;
	label: string;
	type: 'text' | 'number' | 'date' | 'time' | 'textarea' | 'select' | 'tel';
	required: boolean;
	placeholder: string;
	options: string;
	ltr: boolean;
};

export type SmsEventConf = { on: boolean; body: string; vars: string; pattern: string; text: string };

export type Settings = {
	enabled: boolean;
	title: string;
	description: string;
	button: string;
	min_total: number;
	max_total: number;
	cards: Card[];
	card_mode: 'all' | 'random' | 'rotate' | 'first';
	pro: boolean;
	redirect: boolean;
	strict: boolean;
	deadline: number;
	remind: number;
	max_mb: number;
	pdf: boolean;
	multi: boolean;
	multi_max: number;
	multi_min: number;
	ref: 'hidden' | 'optional' | 'required';
	last4: 'hidden' | 'optional' | 'required';
	ref_label: string;
	last4_label: string;
	file_label: string;
	fields: ReceiptField[];
	slug: string;
	paid_status: 'auto' | 'processing' | 'completed';
	expire_status: 'cancelled' | 'failed';
	hold_stock: boolean;
	customer_cancel: boolean;
	customer_note: boolean;
	account_action: boolean;
	account_box: boolean;
	thankyou_box: boolean;
	keep_approved: number;
	keep_rejected: number;
	keep_cancelled: number;
	delete_with_order: boolean;
	page_title: string;
	text_pay: string;
	steps: string;
	text_review: string;
	text_reject: string;
	text_paid: string;
	text_expired: string;
	support_on: boolean;
	support: string;
	support_url: string;
	layout: 'theme' | 'standalone';
	scheme: 'light' | 'dark' | 'auto';
	accent: string;
	accent_source: 'custom' | 'theme';
	accent_theme: string;
	font: 'vazirmatn' | 'theme';
	tilt: boolean;
	logo: boolean;
	sms_driver: 'off' | 'log' | 'melipayamak';
	mp_mode: 'pattern' | 'line';
	mp_user: string;
	mp_pass: string;
	mp_from: string;
	sms_admins: string;
	sms_log: boolean;
	sms_events: Record<string, Partial<SmsEventConf>>;
	cleanup: boolean;
	modules: Modules;
};

/** Optional feature modules: id → settings (every module has `on`). */
export type FeeConf = { on: boolean; type: 'discount' | 'fee'; mode: 'percent' | 'fixed'; percent: number; fixed: number; max: number; min: number; round: number; shipping: boolean; label: string; badge: boolean };
export type LimitsConf = { on: boolean; limits: Record<string, { day: number; month: number }> };
export type RulesConf = { on: boolean; cats_mode: 'off' | 'only' | 'except'; cats: number[]; shipping: string[]; roles: string[]; cards: Record<string, { roles: string[]; min: number; max: number }> };
export type FraudConf = { on: boolean; file: boolean; image: boolean; ref: boolean; action: 'flag' | 'block'; confirm: boolean; sensitivity: 'low' | 'normal' | 'high' };
export type Modules = { fee: FeeConf; limits: LimitsConf; rules: RulesConf; fraud: FraudConf } & Record<string, { on: boolean; [ key: string ]: unknown }>;

export type Option = { value: string; label: string };
export type ThemeColor = { slug: string; name: string; color: string; css: string };
export type ModuleMeta = { id: string; label: string; desc: string; icon: string; data: Record<string, unknown> };

export type Bank = {
	name: string;
	bins: string[];
	codes: string[];
	colors: [ string, string, string ];
	ink: string;
	accent: string;
	logo: string;
};

export type EventDef = { label: string; to: 'customer' | 'admin'; desc: string; vars: string; pattern: string; text: string; on: boolean };

export type Boot = {
	rest: string;
	nonce: string;
	version: string;
	settings: Settings;
	defaults: Settings;
	banks: Record<string, Bank>;
	events: Record<string, EventDef>;
	modules: ModuleMeta[];
	themeColors: ThemeColor[];
	themeName: string;
	tokens: Record<string, string>;
	payBase: string;
	unit: string;
	home: string;
	site: string;
	wcUrl: string;
	ordersUrl: string;
	pretty: boolean;
	blocks: boolean;
};

export type ReceiptFile = { url: string; thumb: string; preview: string; image: boolean; type: string; name: string };

export type Receipt = {
	index: number;
	url: string;
	thumb: string;
	preview: string;
	files: ReceiptFile[];
	image: boolean;
	type: string;
	name: string;
	time: string;
	ref: string;
	last4: string;
	state: 'pending' | 'approved' | 'rejected';
	reason: string;
	fields: Record<string, string>;
	deleted: boolean;
	flags: Flag[];
};

export type Flag = { level: 'danger' | 'warn'; text: string; order: number; number: string; edit: string };

export type OrderRow = {
	id: number;
	number: string;
	status: string;
	label: string;
	customer: string;
	phone: string;
	total: string;
	unit: string;
	date: string;
	deadline: string;
	edit: string;
	pay: string;
	receipts: Receipt[];
	cards: { bank: string; slug: string; number: string }[];
};

export type Stats = {
	review: number;
	awaiting: number;
	paid30: number;
	sum30: number;
	days: number[];
	unit: string;
	enabled: boolean;
	cards: number;
};

declare global {
	interface Window {
		ctcBoot: Boot;
	}
}
