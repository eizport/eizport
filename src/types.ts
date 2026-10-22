/**
 * Shapes of the abilities' outputs and of the screen's state.
 */

export interface Term {
	id: number;
	name: string;
}

export interface ContentType {
	type: string;
	label: string;
	count: number;
	hierarchical: boolean;
	statuses: string[];
	taxonomies: { taxonomy: string; label: string; terms: Term[] }[];
}

export interface ExportOptions {
	types: ContentType[];
	option_groups: { group: string; label: string; options: string[] }[];
	front_pages: { front: number; posts: number };
	themes: {
		stylesheet: string;
		name: string;
		parent: string;
		active: boolean;
	}[];
	plugins: { plugin: string; name: string }[];
	copy_lifetime: number;
	/** Core's file name without extension, like "site.WordPress.2026-10-10". */
	filename: string;
	/** When linked options are added (Settings → Linked settings). */
	linked: 'both' | 'check' | 'export' | 'off';
	/** Approved product reviews (0 without WooCommerce). */
	review_count: number;
	/** Bundles are plain .zip files (Settings → Export). */
	bundle_zip: boolean;
	/** Navigation menus: classic ones and block navigations. */
	menus: {
		classic: ClassicMenu[];
		navigation: NavigationMenu[];
	};
}

/** A classic menu (Appearance → Menus). */
export interface ClassicMenu {
	id: number;
	name: string;
	slug: string;
	/** Menu items. */
	items: number;
	/** Labels of the theme locations it sits in. */
	locations: string[];
}

/** A block navigation (wp_navigation). */
export interface NavigationMenu {
	id: number;
	title: string;
	/** Links in it. */
	links: number;
}

/** What the items chosen suggest exporting too (eizport/get-suggestions). */
export interface Suggestions {
	/** Post type => IDs. */
	items: Record< string, number[] >;
	/** Comment IDs of product reviews. */
	reviews: number[];
	/** Option names. */
	options: string[];
	/** Classic menus in theme locations; navigations the items or the theme use. */
	menus?: { classic: number[]; navigation: number[] };
}

/** A post's role on the site: an option pointing to it (eizport_linked_options). */
export interface Role {
	option: string;
	label: string;
	/** Options that go with it, like show_on_front. */
	with: string[];
}

export interface Item {
	id: number;
	title: string;
	status: string;
	date: string;
	parent: number;
	author: number;
	file: string;
	homepage: boolean;
	posts_page: boolean;
	/** Its roles on the site, from the options pointing to it. */
	roles: Role[];
}

export interface ItemsPage {
	items: Item[];
	total: number;
	total_pages: number;
}

export interface Copy {
	name: string;
	format: 'wxrz' | 'zip' | 'wxr' | 'playground' | 'plugin';
	size: number;
	created: string;
	expires: string;
	download_url: string;
	playground_url: string;
	command: string;
}

export interface Filters {
	status?: string;
	author?: number;
	start_date?: string;
	end_date?: string;
	terms?: number[];
}

/** What to export of one type: all of it, filtered, or the items checked. */
export interface TypeChoice {
	ids?: number[];
	filters?: Filters;
}

export interface FileSettings {
	format: 'wxr' | 'playground';
	filename: string;
	media: boolean;
	/** Options to export, by name (eizport/get-options). */
	option_names: string[];
	option_all: boolean;
	option_groups: string[];
	/** Rows that bring what the items chosen suggest (post types, "__options", "__reviews", "__menus"). */
	include_suggested: string[];
	/** A plugin .zip holding the bundle. */
	as_plugin: boolean;
	/** The bundle asks its importer to update the items that already exist. */
	update_existing: boolean;
	/** Product reviews chosen one by one (comment IDs). */
	review_ids: number[];
	/** Every product review. */
	review_all: boolean;
	/** Menus chosen one by one: "c:<term ID>" (classic), "n:<post ID>" (navigation). */
	menu_ids: string[];
	/** Every menu. */
	menu_all: boolean;
	themes: string[];
	plugins: string[];
}

export interface ExportState {
	/** Post type => choice; empty means all content. */
	types: Record< string, TypeChoice >;
	settings: FileSettings;
}

export interface Settings {
	copy_lifetime: number;
	replace_export: boolean;
	classic_ui: boolean;
	style_import: boolean;
	import_direct: boolean;
	content_files: boolean;
	attached_only: boolean;
	bundle_zip: boolean;
	plugin_content: 'wxrz' | 'root' | 'folder';
	plugin_import_again: boolean;
	defaults_scope: 'user' | 'site';
	linked_options: 'both' | 'check' | 'export' | 'off';
	lifetime_choices: { value: number; label: string }[];
	scope_choices: { value: string; label: string }[];
	linked_choices: { value: string; label: string }[];
	plugin_content_choices: { value: string; label: string }[];
}

export interface OptionRow {
	name: string;
	size: number;
	/** The value in a line (arrays as JSON), up to 200 characters. */
	value: string;
	autoload: boolean;
	post_id: boolean;
}

export interface Review {
	id: number;
	author: string;
	/** The product's ID and name. */
	product: number;
	product_title: string;
	/** 1–5, or 0 without a rating. */
	rating: number;
	excerpt: string;
	date: string;
}

export interface ReviewsPage {
	items: Review[];
	total: number;
	total_pages: number;
}

export interface OptionsPage {
	items: OptionRow[];
	total: number;
	total_pages: number;
}
