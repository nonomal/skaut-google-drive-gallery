type Options = 'grid_height'|'grid_spacing'|'dir_counts'|'page_size'|'page_autoload'|'preview_size'|'preview_loop';

type OrderingOptions = 'image_ordering'|'dir_ordering';

declare interface Option {
	default: string;
	name: string;
}

declare interface OrderingOption {
	default_by: string;
	default_order: string;
	name: string;
}

interface SgdgBlockLocalize {
	ajax_url: string;
	nonce: string;
	block_name: string;
	block_description: string;
	root_name: string;
	settings_override: string;
	grid_section_name: string;
	lightbox_section_name: string;
	ordering_option_ascending: string;
	ordering_option_descending: string;
	ordering_option_by_time: string;
	ordering_option_by_name: string;
}

declare const sgdgBlockLocalize: SgdgBlockLocalize & Record<Options, Option> & Record<OrderingOptions, OrderingOption>;
