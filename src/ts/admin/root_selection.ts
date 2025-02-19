import $ from 'jquery';

import { isError } from '../isError';
import { printError } from '../printError';

let path: Array<string> = sgdgRootpathLocalize.root_dir;

function resetWarn(message: string): void {
	const html =
		'<div class="notice notice-warning">' +
		'<p>' +
		message +
		'</p>' +
		'</div>';
	$(html).insertBefore('.sgdg_root_selection');
}

function pathClick(el: HTMLElement): void {
	const stop = $(el).data('id') as string;
	path = path.slice(0, path.indexOf(stop) + 1);
	// eslint-disable-next-line @typescript-eslint/no-use-before-define -- Cyclical dependency
	listGdriveDir();
}

function click(el: HTMLElement): void {
	const newId = $(el).data('id') as string;
	if (newId) {
		path.push(newId);
	} else {
		path.pop();
	}
	// eslint-disable-next-line @typescript-eslint/no-use-before-define -- Cyclical dependency
	listGdriveDir();
}

function success(data: ListGdriveDirSuccessResponse): void {
	let html = '';
	if (0 < path.length) {
		html +=
			'<tr>' +
			'<td class="row-title">' +
			'<label>' +
			'..' +
			'</label>' +
			'</td>' +
			'</tr>';
	}
	for (let i = 0; i < data.directories.length; i++) {
		html += '<tr class="';
		if (
			(0 === path.length && 1 === i % 2) ||
			(0 < path.length && 0 === i % 2)
		) {
			html += 'alternate';
		}
		html +=
			'">' +
			'<td class="row-title">' +
			'<label data-id="' +
			data.directories[i].id +
			'">' +
			data.directories[i].name +
			'</label>' +
			'</td>' +
			'</tr>';
	}
	$('#sgdg_root_selection_body').html(html);

	html = '';
	if (0 === path.length) {
		html = sgdgRootpathLocalize.drive_list;
	} else {
		$('#submit').removeAttr('disabled');
	}
	for (let i = 0; i < path.length; i++) {
		if (0 < i) {
			html += ' > ';
		}
		html += '<a data-id="' + path[i] + '">' + data.path[i] + '</a>';
	}
	$('.sgdg-root-selection-path').html(html);
	$('.sgdg-root-selection-path a').on('click', function () {
		pathClick(this);
	});
	$('#sgdg_root_selection_body label').on('click', function () {
		click(this);
	});
	$('#sgdg_root_path').val(JSON.stringify(path));
}

function listGdriveDir(): void {
	$('#sgdg_root_selection_body').html('');
	$('#submit').attr('disabled', 'disabled');
	void $.get(
		sgdgRootpathLocalize.ajax_url,
		{
			_ajax_nonce: sgdgRootpathLocalize.nonce,
			action: 'list_gdrive_dir',
			path,
		},
		(data: ListGdriveDirResponse) => {
			if (isError(data)) {
				$('.sgdg_root_selection').replaceWith(
					printError(data, sgdgRootpathLocalize)
				);
				return;
			}
			if (data.resetWarn !== undefined) {
				path = [];
				resetWarn(data.resetWarn);
			}
			success(data);
		}
	);
}

listGdriveDir();
