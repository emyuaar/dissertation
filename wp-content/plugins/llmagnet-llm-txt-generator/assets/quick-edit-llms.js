/**
 * Quick Edit support for the "Include in llms.txt" toggle (adoption plan
 * §5.3, Phase E Lane E1). Populates the checkbox rendered by
 * Post_Meta::render_quick_edit_field() from the hidden per-row state marker
 * (.llmagnet-llms-include-state) when a row enters inline-edit mode.
 *
 * Plain JS + jQuery (depends on core's inline-edit-post) — no React on
 * edit.php, per the adoption plan's bundle-discipline rule.
 */
(function ($) {
	'use strict';

	if (typeof window.inlineEditPost === 'undefined') {
		return;
	}

	var coreEdit = window.inlineEditPost.edit;

	window.inlineEditPost.edit = function (id) {
		coreEdit.apply(this, arguments);

		var postId = 0;
		if (typeof id === 'object') {
			postId = parseInt(this.getId(id), 10);
		} else {
			postId = parseInt(id, 10);
		}
		if (!postId) {
			return;
		}

		var state = $('#post-' + postId)
			.find('.llmagnet-llms-include-state')
			.attr('data-included');

		// Missing marker (column filtered away) defaults to included.
		$(':input[name="llmagnet_include_in_llms"]', '#edit-' + postId).prop(
			'checked',
			state !== '0'
		);
	};
})(jQuery);
