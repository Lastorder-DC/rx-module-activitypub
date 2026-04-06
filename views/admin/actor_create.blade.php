<div class="x_page-header">
	<h1>{{ $lang->cmd_activitypub }}</h1>
</div>

<ul class="x_nav x_nav-tabs">
	<li>
		<a href="@url(['module' => 'admin', 'act' => 'dispActivitypubAdminConfig'])">{{ $lang->cmd_activitypub_general_config }}</a>
	</li>
</ul>

<div class="x_page-header" style="margin-top:10px;">
	<h2>{{ $lang->cmd_activitypub_create_actor }}</h2>
</div>

<form class="x_form-horizontal" action="./" method="post">
	<input type="hidden" name="module" value="activitypub" />
	<input type="hidden" name="act" value="procActivitypubAdminCreateActor" />
	<input type="hidden" name="xe_validator_id" value="modules/activitypub/views/admin/actor_create/1" />

	@if (!empty($XE_VALIDATOR_MESSAGE) && $XE_VALIDATOR_ID == 'modules/activitypub/views/admin/actor_create/1')
		<div class="message {{ $XE_VALIDATOR_MESSAGE_TYPE }}">
			<p>{{ $XE_VALIDATOR_MESSAGE }}</p>
		</div>
	@endif

	<section class="section">
		<h3>{{ $lang->cmd_activitypub_actor_type }}</h3>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_actor_type }}</label>
			<div class="x_controls">
				<label class="x_inline">
					<input type="radio" name="actor_type" value="board" checked onchange="document.getElementById('board_fields').style.display='block';document.getElementById('user_fields').style.display='none';" /> {{ $lang->cmd_activitypub_type_board }}
				</label>
				<label class="x_inline">
					<input type="radio" name="actor_type" value="user" onchange="document.getElementById('board_fields').style.display='none';document.getElementById('user_fields').style.display='block';" /> {{ $lang->cmd_activitypub_type_user }}
				</label>
			</div>
		</div>

		<div id="board_fields">
			<div class="x_control-group">
				<label class="x_control-label" for="target_mid">{{ $lang->cmd_activitypub_target_mid }}</label>
				<div class="x_controls">
					<input type="text" name="target_mid" id="target_mid" placeholder="board1" style="width:200px;" />
					<p class="x_help-block">{{ $lang->cmd_activitypub_target_mid_desc }}</p>
				</div>
			</div>
		</div>

		<div id="user_fields" style="display:none;">
			<div class="x_control-group">
				<label class="x_control-label" for="target_member_srl">{{ $lang->cmd_activitypub_target_member }}</label>
				<div class="x_controls">
					<input type="number" name="target_member_srl" id="target_member_srl" placeholder="member_srl" style="width:200px;" />
					<p class="x_help-block">{{ $lang->cmd_activitypub_target_member_desc }}</p>
				</div>
			</div>

			<div class="x_control-group">
				<label class="x_control-label" for="filter_mids">{{ $lang->cmd_activitypub_filter_mids }}</label>
				<div class="x_controls">
					<textarea name="filter_mids" id="filter_mids" rows="3" cols="60" placeholder="board1, board2"></textarea>
					<p class="x_help-block">{{ $lang->cmd_activitypub_filter_mids_desc }}</p>
				</div>
			</div>
		</div>

		<h3>{{ $lang->cmd_activitypub_actor_profile }}</h3>

		<div class="x_control-group">
			<label class="x_control-label" for="preferred_username">{{ $lang->cmd_activitypub_actor_username }}</label>
			<div class="x_controls">
				<input type="text" name="preferred_username" id="preferred_username" placeholder="my_actor" style="width:200px;" required />
				<span class="x_text-muted">{{ '@' . $site_domain }}</span>
				<p class="x_help-block">{{ $lang->cmd_activitypub_username_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="display_name">{{ $lang->cmd_activitypub_display_name }}</label>
			<div class="x_controls">
				<input type="text" name="display_name" id="display_name" style="width:300px;" />
				<p class="x_help-block">{{ $lang->cmd_activitypub_display_name_desc_create }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="actor_summary">{{ $lang->cmd_activitypub_actor_summary }}</label>
			<div class="x_controls">
				<textarea name="summary" id="actor_summary" rows="3" cols="60"></textarea>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="icon_url">{{ $lang->cmd_activitypub_icon_url }}</label>
			<div class="x_controls">
				<input type="url" name="icon_url" id="icon_url" placeholder="https://example.com/image.png" style="width:400px;" />
				<p class="x_help-block">{{ $lang->cmd_activitypub_icon_url_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_hide_followers }}</label>
			<div class="x_controls">
				<label class="x_inline">
					<input type="radio" name="hide_followers" value="Y" /> {{ $lang->cmd_yes }}
				</label>
				<label class="x_inline">
					<input type="radio" name="hide_followers" value="N" checked /> {{ $lang->cmd_no }}
				</label>
				<p class="x_help-block">{{ $lang->cmd_activitypub_hide_followers_desc }}</p>
			</div>
		</div>

		<h3>{{ $lang->cmd_activitypub_ap_settings }}</h3>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_discoverable }}</label>
			<div class="x_controls">
				<label class="x_inline">
					<input type="radio" name="discoverable" value="Y" checked /> {{ $lang->cmd_yes }}
				</label>
				<label class="x_inline">
					<input type="radio" name="discoverable" value="N" /> {{ $lang->cmd_no }}
				</label>
				<p class="x_help-block">{{ $lang->cmd_activitypub_discoverable_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_indexable }}</label>
			<div class="x_controls">
				<label class="x_inline">
					<input type="radio" name="indexable" value="Y" /> {{ $lang->cmd_yes }}
				</label>
				<label class="x_inline">
					<input type="radio" name="indexable" value="N" checked /> {{ $lang->cmd_no }}
				</label>
				<p class="x_help-block">{{ $lang->cmd_activitypub_indexable_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_visibility }}</label>
			<div class="x_controls">
				<select name="visibility" style="width:200px;">
					<option value="public">{{ $lang->cmd_activitypub_visibility_public }}</option>
					<option value="unlisted" selected>{{ $lang->cmd_activitypub_visibility_unlisted }}</option>
					<option value="private">{{ $lang->cmd_activitypub_visibility_private }}</option>
					<option value="direct">{{ $lang->cmd_activitypub_visibility_direct }}</option>
				</select>
				<p class="x_help-block">{{ $lang->cmd_activitypub_visibility_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_quote_policy }}</label>
			<div class="x_controls">
				<select name="quote_policy" style="width:200px;">
					<option value="public">{{ $lang->cmd_activitypub_quote_policy_public }}</option>
					<option value="followers">{{ $lang->cmd_activitypub_quote_policy_followers }}</option>
					<option value="following">{{ $lang->cmd_activitypub_quote_policy_following }}</option>
					<option value="nobody" selected>{{ $lang->cmd_activitypub_quote_policy_nobody }}</option>
				</select>
				<p class="x_help-block">{{ $lang->cmd_activitypub_quote_policy_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_category_filter_mode }}</label>
			<div class="x_controls">
				<select name="category_filter_mode" style="width:300px;">
					<option value="off" selected>{{ $lang->cmd_activitypub_category_filter_off }}</option>
					<option value="include">{{ $lang->cmd_activitypub_category_filter_include }}</option>
					<option value="exclude">{{ $lang->cmd_activitypub_category_filter_exclude }}</option>
				</select>
				<p class="x_help-block">{{ $lang->cmd_activitypub_category_filter_mode_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="category_filter_srls">{{ $lang->cmd_activitypub_category_filter_srls }}</label>
			<div class="x_controls">
				<input type="text" name="category_filter_srls" id="category_filter_srls" placeholder="1,2,3" style="width:400px;" />
				<p class="x_help-block">{{ $lang->cmd_activitypub_category_filter_srls_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_attach_thumbnail }}</label>
			<div class="x_controls">
				<label class="x_inline">
					<input type="radio" name="attach_thumbnail" value="Y" /> {{ $lang->cmd_yes }}
				</label>
				<label class="x_inline">
					<input type="radio" name="attach_thumbnail" value="N" checked /> {{ $lang->cmd_no }}
				</label>
				<p class="x_help-block">{{ $lang->cmd_activitypub_attach_thumbnail_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_sensitive_mode }}</label>
			<div class="x_controls">
				<select name="sensitive_mode" style="width:200px;">
					<option value="off" selected>{{ $lang->cmd_activitypub_sensitive_off }}</option>
					<option value="always">{{ $lang->cmd_activitypub_sensitive_always }}</option>
					<option value="category">{{ $lang->cmd_activitypub_sensitive_category }}</option>
				</select>
				<p class="x_help-block">{{ $lang->cmd_activitypub_sensitive_mode_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="sensitive_category_srls">{{ $lang->cmd_activitypub_sensitive_category_srls }}</label>
			<div class="x_controls">
				<input type="text" name="sensitive_category_srls" id="sensitive_category_srls" placeholder="1,2,3" style="width:400px;" />
				<p class="x_help-block">{{ $lang->cmd_activitypub_sensitive_category_srls_desc }}</p>
			</div>
		</div>
	</section>

	<div class="btnArea x_clearfix">
		<a href="@url(['module' => 'admin', 'act' => 'dispActivitypubAdminConfig'])" class="x_btn x_pull-left">{{ $lang->cmd_back }}</a>
		<button type="submit" class="x_btn x_btn-primary x_pull-right">{{ $lang->cmd_registration }}</button>
	</div>
</form>
