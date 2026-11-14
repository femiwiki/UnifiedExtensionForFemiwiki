/**
 * Checks the daily edit limit when someone without an account follows an edit link, and shows
 * a dialog instead of opening the editor once the limit is used up.
 */
(() => {
	const LIMITED = "unifiedextensionforfemiwiki-logged-out-edit-limit";
	const CLOSED = "unifiedextensionforfemiwiki-logged-out-editing-closed";
	const NAMESPACE_CLOSED =
		"unifiedextensionforfemiwiki-logged-out-editing-namespace";
	const CODES = [LIMITED, CLOSED, NAMESPACE_CLOSED];
	const CHECKED = "ueffEditLimitChecked";
	let windowManager = null;
	// Loaded only when an edit link is followed, to keep page views light
	const DEPENDENCIES = [
		"mediawiki.api",
		"mediawiki.util",
		"mediawiki.language",
		"oojs-ui-windows",
	];

	/**
	 * @param {HTMLAnchorElement} link
	 * @return {string|null} Title the link edits, or null when it is not an edit link
	 */
	function editedTitle(link) {
		let url;
		try {
			url = new URL(link.href, location.href);
		} catch (_e) {
			return null;
		}
		if (url.origin !== location.origin) {
			return null;
		}
		const action = url.searchParams.get("action");
		if (
			action !== "edit" &&
			action !== "submit" &&
			!url.searchParams.has("veaction")
		) {
			return null;
		}
		const title = url.searchParams.get("title");
		if (title) {
			return title;
		}
		const prefix = mw.config.get("wgArticlePath").replace("$1", "");
		return url.pathname.startsWith(prefix)
			? decodeURIComponent(url.pathname.slice(prefix.length))
			: null;
	}

	/**
	 * @param {string} title
	 * @return {jQuery.Promise<Object|null>} The refusal, or null when editing is allowed
	 */
	function checkLimit(title) {
		return new mw.Api()
			.get({
				action: "query",
				prop: "info",
				titles: title,
				intestactions: "edit",
				intestactionsdetail: "full",
				errorformat: "raw",
				formatversion: 2,
			})
			.then((data) => {
				const page = data.query?.pages?.[0];
				const errors = page?.actions?.edit || [];
				return errors.find((e) => CODES.includes(e.code)) || null;
			});
	}

	/**
	 * @param {Object} error Refusal from the API, in raw error format
	 */
	function showDialog(error) {
		if (!windowManager) {
			windowManager = new OO.ui.WindowManager();
			$(OO.ui.getTeleportTarget()).append(windowManager.$element);
		}
		const dialog = new OO.ui.MessageDialog({ size: "medium" });
		windowManager.addWindows([dialog]);
		const returnTo = { returnto: mw.config.get("wgPageName") };
		windowManager
			.openWindow(dialog, {
				// Message keys are the refusal code with -title or -dialog appended
				title: mw.msg(`${error.code}-title`),
				message:
					error.code === LIMITED
						? mw.msg(
								`${LIMITED}-dialog`,
								mw.language.convertNumber(Number(error.params[0].num)),
							)
						: mw.msg(`${error.code}-dialog`),
				actions: [
					{
						action: "createaccount",
						label: mw.msg("createaccount"),
						flags: ["primary", "progressive"],
					},
					{ action: "login", label: mw.msg("login") },
					{
						action: "close",
						label: OO.ui.msg("ooui-popup-widget-close-button-aria-label"),
						flags: "safe",
					},
				],
			})
			.closed.then((data) => {
				windowManager.removeWindows([dialog]);
				const action = data?.action;
				if (action === "createaccount") {
					location.href = mw.util.getUrl("Special:CreateAccount", returnTo);
				} else if (action === "login") {
					location.href = mw.util.getUrl("Special:UserLogin", returnTo);
				}
			});
	}

	// Capture phase, so this runs before VisualEditor's own click handlers
	document.addEventListener(
		"click",
		(event) => {
			if (
				event.button !== 0 ||
				event.ctrlKey ||
				event.metaKey ||
				event.shiftKey ||
				event.altKey
			) {
				return;
			}
			const link = event.target.closest?.("a[href]");
			if (!link) {
				return;
			}
			if (link.dataset[CHECKED]) {
				delete link.dataset[CHECKED];
				return;
			}
			const title = editedTitle(link);
			if (!title) {
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			mw.loader
				.using(DEPENDENCIES)
				.then(() => checkLimit(title))
				.then((error) => {
					if (error === null) {
						return $.Deferred().reject().promise();
					}
					showDialog(error);
				})
				.catch(() => {
					// Allowed, or the check failed: open the editor as the link would have
					link.dataset[CHECKED] = "1";
					link.click();
				});
		},
		true,
	);
})();
