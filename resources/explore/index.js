/**
 * "더 둘러보기" below the footer: linked articles first, then articles in the topics the reader
 * asked to see more of, then random articles without end. The list comes from
 * list=femiwikiexplore; see ExploreFeed.
 */
(() => {
	const footer = document.querySelector("#footer, .mw-footer");
	if (!footer || !("IntersectionObserver" in window)) {
		return;
	}

	// TextExtracts gives at most 20 extracts a request
	const BATCH_SIZE = 18;
	// Each batch is a grid of its own, so one that fills its last row in one,
	// two or three columns leaves no gap before the next
	const ROW_MULTIPLE = 6;
	const MORE_OPTION = "femiwiki-explore-more";
	const LESS_OPTION = "femiwiki-explore-less";
	const MENU_MESSAGES = {
		more: "unifiedextensionforfemiwiki-explore-menu-more",
		less: "unifiedextensionforfemiwiki-explore-menu-less",
		normal: "unifiedextensionforfemiwiki-explore-menu-normal",
	};
	const SAVED_MESSAGES = {
		more: "unifiedextensionforfemiwiki-explore-saved-more",
		less: "unifiedextensionforfemiwiki-explore-saved-less",
		normal: "unifiedextensionforfemiwiki-explore-saved-normal",
	};

	const api = new mw.Api();
	const pageName = mw.config.get("wgPageName");
	const topicPrefix = mw.config.get("wgFemiwikiExploreTopicPrefix", "");
	const canTune = mw.user.isNamed() && topicPrefix !== "";
	const seen = new Set([mw.config.get("wgTitle")]);
	const smooth = !window.matchMedia("(prefers-reduced-motion: reduce)").matches;
	let offset = 0;
	// Pages beyond the last full row, kept for the next batch
	let carry = [];

	const element = (tag, className, text) => {
		const el = document.createElement(tag);
		if (className) {
			el.className = className;
		}
		if (text) {
			el.textContent = text;
		}
		return el;
	};

	const getTopicOption = (name) => {
		try {
			const topics = JSON.parse(mw.user.options.get(name) || "[]");
			return Array.isArray(topics) ? topics : [];
		} catch (_e) {
			return [];
		}
	};
	const topicKey = (topic) => topic.replace(/ /g, "_");
	const topicLabel = (topic) => topic.replace(/_/g, " ");
	const getTopicState = (topic) => {
		const key = topicKey(topic);
		if (getTopicOption(MORE_OPTION).indexOf(key) !== -1) {
			return "more";
		}
		return getTopicOption(LESS_OPTION).indexOf(key) !== -1 ? "less" : "normal";
	};

	const fetchBatch = () => {
		const request = api.get({
			action: "query",
			formatversion: 2,
			generator: "femiwikiexplore",
			gfwetitle: pageName,
			gfweoffset: offset,
			gfwelimit: BATCH_SIZE,
			prop: "pageimages|extracts",
			piprop: "thumbnail",
			pithumbsize: 250,
			pilimit: BATCH_SIZE,
			exintro: 1,
			explaintext: 1,
			exsentences: 1,
			exlimit: BATCH_SIZE,
		});
		offset += BATCH_SIZE;
		return request.then((data) => {
			const fresh = (data.query?.pages || [])
				.filter((page) => !page.missing && !seen.has(page.title))
				.sort((a, b) => a.index - b.index);
			for (const page of fresh) {
				seen.add(page.title);
				if (page.thumbnail) {
					new Image().src = page.thumbnail.source;
				}
			}
			const pages = carry.concat(fresh);
			const full =
				Math.floor(pages.length / ROW_MULTIPLE) * ROW_MULTIPLE || pages.length;
			carry = pages.slice(full);
			return pages.slice(0, full);
		});
	};

	// One menu open at a time, for the topic tags of signed-in readers
	let openMenu = null;
	const closeMenu = (focusTag) => {
		if (!openMenu) {
			return;
		}
		const { menu, tag } = openMenu;
		openMenu = null;
		menu.remove();
		tag.setAttribute("aria-expanded", "false");
		if (focusTag) {
			tag.focus();
		}
	};
	document.addEventListener("click", (ev) => {
		if (
			openMenu &&
			!openMenu.menu.contains(ev.target) &&
			ev.target !== openMenu.tag
		) {
			closeMenu(false);
		}
	});
	document.addEventListener("keydown", (ev) => {
		if (openMenu && ev.key === "Escape") {
			closeMenu(true);
		}
	});

	const updateTags = (topic) => {
		const state = getTopicState(topic);
		for (const tag of document.querySelectorAll(".fw-explore-tag")) {
			if (tag.dataset.topic === topicKey(topic)) {
				tag.dataset.state = state;
			}
		}
	};

	const setTopicState = (topic, state) => {
		const key = topicKey(topic);
		const more = getTopicOption(MORE_OPTION).filter((t) => t !== key);
		const less = getTopicOption(LESS_OPTION).filter((t) => t !== key);
		if (state === "more") {
			more.push(key);
		} else if (state === "less") {
			less.push(key);
		}
		const options = {};
		options[MORE_OPTION] = JSON.stringify(more);
		options[LESS_OPTION] = JSON.stringify(less);
		api.saveOptions(options).then(
			() => {
				mw.user.options.set(options);
				updateTags(topic);
				mw.notify(mw.msg(SAVED_MESSAGES[state], topicLabel(topic)));
			},
			() => {
				mw.notify(mw.msg("unifiedextensionforfemiwiki-explore-save-failed"), {
					type: "error",
				});
			},
		);
	};

	const openTopicMenu = (tag, topic) => {
		const current = getTopicState(topic);
		const menu = element("div", "fw-explore-menu");
		menu.setAttribute("role", "menu");
		const items = [];
		for (const state of ["more", "less", "normal"]) {
			if (state === current) {
				continue;
			}
			const item = element(
				"button",
				"fw-explore-menu-item",
				mw.msg(MENU_MESSAGES[state], topicLabel(topic)),
			);
			item.type = "button";
			item.setAttribute("role", "menuitem");
			item.addEventListener("click", () => {
				closeMenu(true);
				setTopicState(topic, state);
			});
			items.push(item);
		}
		const category = element(
			"a",
			"fw-explore-menu-item",
			mw.msg("unifiedextensionforfemiwiki-explore-menu-category"),
		);
		category.href = mw.util.getUrl(
			`Category:${topicPrefix}${topicLabel(topic)}`,
		);
		category.setAttribute("role", "menuitem");
		items.push(category);
		menu.append(...items);
		menu.addEventListener("keydown", (ev) => {
			const index = items.indexOf(document.activeElement);
			if (ev.key === "ArrowDown" || ev.key === "ArrowUp") {
				ev.preventDefault();
				const step = ev.key === "ArrowDown" ? 1 : items.length - 1;
				items[(index + step) % items.length].focus();
			}
		});
		tag.after(menu);
		tag.setAttribute("aria-expanded", "true");
		openMenu = { menu, tag };
		items[0].focus();
	};

	const topicTag = (topic) => {
		const text = `#${topicLabel(topic)}`;
		let tag;
		if (canTune) {
			tag = element("button", "fw-explore-tag", text);
			tag.type = "button";
			tag.setAttribute("aria-haspopup", "menu");
			tag.setAttribute("aria-expanded", "false");
			tag.dataset.state = getTopicState(topic);
			tag.addEventListener("click", () => {
				const wasOpen = openMenu && openMenu.tag === tag;
				closeMenu(false);
				if (!wasOpen) {
					openTopicMenu(tag, topic);
				}
			});
		} else {
			tag = element("a", "fw-explore-tag", text);
			tag.href = mw.util.getUrl(`Category:${topicPrefix}${topicLabel(topic)}`);
		}
		tag.dataset.topic = topicKey(topic);
		return tag;
	};

	const card = (page) => {
		const li = element("li", "fw-explore-item");
		li.dataset.source = page.source;
		const body = element("div", "cdx-card");
		const thumbnail = element("span", "cdx-card__thumbnail cdx-thumbnail");
		if (page.thumbnail) {
			const image = element("span", "cdx-thumbnail__image");
			image.style.backgroundImage = `url(${JSON.stringify(page.thumbnail.source)})`;
			thumbnail.append(image);
		} else {
			const placeholder = element("span", "cdx-thumbnail__placeholder");
			placeholder.append(element("span", "cdx-thumbnail__placeholder__icon"));
			thumbnail.append(placeholder);
		}
		const text = element("span", "cdx-card__text");
		// The title link covers the whole card; the topic tags sit above it
		const link = element(
			"a",
			"cdx-card__text__title fw-explore-link",
			page.title,
		);
		link.href = mw.util.getUrl(page.title);
		text.append(link);
		if (page.extract) {
			text.append(element("span", "cdx-card__text__description", page.extract));
		}
		if (page.topics?.length) {
			const tags = element("span", "fw-explore-tags");
			tags.setAttribute(
				"aria-label",
				mw.msg("unifiedextensionforfemiwiki-explore-topics"),
			);
			tags.append(...page.topics.map(topicTag));
			text.append(tags);
		}
		body.append(thumbnail, text);
		li.append(body);
		return li;
	};

	const skeleton = () => {
		const li = element("li", "fw-explore-skeleton");
		li.setAttribute("aria-hidden", "true");
		const body = element("span", "cdx-card");
		const thumbnail = element("span", "cdx-card__thumbnail cdx-thumbnail");
		thumbnail.append(element("span", "cdx-thumbnail__placeholder"));
		const text = element("span", "cdx-card__text");
		for (let i = 0; i < 3; i++) {
			text.append(element("span", "fw-explore-bar"));
		}
		body.append(thumbnail, text);
		li.append(body);
		return li;
	};

	// The section goes in at once, so the random page link has a target
	const section = element("section", "fw-explore noprint");
	section.setAttribute("aria-labelledby", "fw-explore-heading");
	const heading = element(
		"h2",
		"fw-explore-heading",
		mw.msg("unifiedextensionforfemiwiki-explore-heading"),
	);
	heading.id = "fw-explore-heading";
	heading.tabIndex = -1;
	const batches = element("div", "fw-explore-batches");
	const pending = element("ul", "fw-explore-list fw-explore-pending");
	pending.append(skeleton(), skeleton());
	const sentinel = element("div", "fw-explore-sentinel");
	// Only after a failed request, to try again
	const retry = element(
		"button",
		"fw-explore-retry",
		mw.msg("unifiedextensionforfemiwiki-explore-retry"),
	);
	retry.type = "button";
	retry.hidden = true;
	section.append(heading, batches, pending, sentinel, retry);
	footer.after(section);
	// Line up with the footer, whose gutters the skin sets by width
	const matchFooter = () => {
		const style = getComputedStyle(footer);
		for (const property of [
			"paddingLeft",
			"paddingRight",
			"marginLeft",
			"marginRight",
			"maxWidth",
		]) {
			section.style[property] = style[property];
		}
		section.style.width = `${footer.getBoundingClientRect().width}px`;
	};
	matchFooter();
	new ResizeObserver(matchFooter).observe(footer);

	// Batches far off screen give up their cards and keep only their height,
	// so the page stays light however far the reader goes
	const pagesOf = new Map();
	const unloader = new IntersectionObserver(
		(entries) => {
			for (const { target, isIntersecting } of entries) {
				const empty = !target.firstChild;
				if (isIntersecting && empty) {
					target.append(...pagesOf.get(target).map(card));
					target.style.height = "";
				} else if (
					!isIntersecting &&
					!empty &&
					!target.contains(document.activeElement)
				) {
					target.style.height = `${target.offsetHeight}px`;
					target.replaceChildren();
				}
			}
		},
		{ rootMargin: "300% 0px" },
	);

	// Two batches in hand: each asked for as soon as the one before arrives
	const queue = [];
	let chaining = false;
	const fetchAhead = () => {
		if (queue.length >= 2) {
			chaining = false;
			return;
		}
		chaining = true;
		const batch = fetchBatch();
		// A batch fetched ahead may fail unseen; showNext() handles it if shown
		batch.catch(() => {});
		queue.push(batch);
		batch.then(fetchAhead, () => {
			chaining = false;
		});
	};
	const prefetch = () => {
		if (!chaining) {
			fetchAhead();
		}
	};

	let busy = false;
	let stopped = false;
	let observer;
	const showNext = () => {
		if (busy || stopped) {
			return;
		}
		busy = true;
		section.setAttribute("aria-busy", "true");
		prefetch();
		const batch = queue.shift();
		prefetch();
		const settle = () => {
			busy = false;
			section.removeAttribute("aria-busy");
		};
		batch.then(
			(pages) => {
				if (!pages.length) {
					// Every page of a small wiki has been shown
					settle();
					stopped = true;
					pending.hidden = true;
					return;
				}
				const list = element("ul", "fw-explore-list");
				pagesOf.set(list, pages);
				list.append(...pages.map(card));
				batches.append(list);
				unloader.observe(list);
				mw.hook("ext.unifiedExtensionForFemiwiki.explore.batch").fire(
					list,
					pages,
				);
				settle();
				// Look again: the sentinel may still be in range
				observer.unobserve(sentinel);
				observer.observe(sentinel);
			},
			() => {
				settle();
				stopped = true;
				pending.hidden = true;
				retry.hidden = false;
			},
		);
	};

	observer = new IntersectionObserver(
		(entries) => {
			if (entries.some((entry) => entry.isIntersecting)) {
				showNext();
			}
		},
		{ rootMargin: "0px 0px 200% 0px" },
	);
	observer.observe(sentinel);

	// The API takes over a second, so fetch the first batch five screens early
	const early = new IntersectionObserver(
		(entries) => {
			if (entries.some((entry) => entry.isIntersecting)) {
				early.disconnect();
				prefetch();
			}
		},
		{ rootMargin: "0px 0px 500% 0px" },
	);
	early.observe(footer);

	retry.addEventListener("click", () => {
		retry.hidden = true;
		pending.hidden = false;
		stopped = false;
		showNext();
	});

	section.addEventListener("click", (ev) => {
		const link = ev.target.closest(".fw-explore-link");
		if (!link) {
			return;
		}
		const links = Array.from(section.querySelectorAll(".fw-explore-link"));
		const item = link.closest(".fw-explore-item");
		mw.hook("ext.unifiedExtensionForFemiwiki.explore.click").fire({
			position: links.indexOf(link) + 1,
			source: item?.dataset.source,
		});
	});

	// While the feed fills the screen, a bubble leads back to the footer
	// A primary button, so the skin's colors for one keep the label readable
	const back = element(
		"button",
		"fw-explore-back cdx-button cdx-button--action-progressive cdx-button--weight-primary",
	);
	back.type = "button";
	back.hidden = true;
	const arrow = element("span", null, "↑");
	arrow.setAttribute("aria-hidden", "true");
	back.append(arrow, ` ${mw.msg("unifiedextensionforfemiwiki-explore-back")}`);
	document.body.append(back);
	footer.tabIndex = -1;
	back.addEventListener("click", () => {
		footer.scrollIntoView({
			behavior: smooth ? "smooth" : "auto",
			block: "end",
		});
		footer.focus({ preventScroll: true });
	});
	let ticking = false;
	const updateBack = () => {
		ticking = false;
		// Once the footer has gone off the top, the feed fills the screen
		back.hidden = footer.getBoundingClientRect().bottom > 0;
	};
	window.addEventListener(
		"scroll",
		() => {
			if (!ticking) {
				ticking = true;
				requestAnimationFrame(updateBack);
			}
		},
		{ passive: true },
	);

	// The random page link scrolls down to the feed instead. New tabs, other
	// pages and readers without JavaScript still get Special:Random.
	document.addEventListener("click", (ev) => {
		if (
			ev.defaultPrevented ||
			ev.button !== 0 ||
			ev.ctrlKey ||
			ev.metaKey ||
			ev.shiftKey ||
			ev.altKey
		) {
			return;
		}
		if (!ev.target.closest("li.random a, #n-randompage a")) {
			return;
		}
		ev.preventDefault();
		if (!batches.firstChild) {
			showNext();
		}
		heading.scrollIntoView({
			behavior: smooth ? "smooth" : "auto",
			block: "start",
		});
		heading.focus({ preventScroll: true });
		// Lazy images above still grow after the scroll, so hold the feed in
		// place until the reader scrolls on their own
		const land = () => heading.scrollIntoView({ block: "start" });
		const pin = new ResizeObserver(land);
		const unpin = () => {
			pin.disconnect();
			for (const type of ["wheel", "touchstart", "keydown"]) {
				window.removeEventListener(type, unpin);
			}
		};
		const hold = () => {
			pin.observe(document.body);
			for (const type of ["wheel", "touchstart", "keydown"]) {
				window.addEventListener(type, unpin, { passive: true });
			}
			setTimeout(unpin, 3000);
		};
		if (smooth && "onscrollend" in window) {
			window.addEventListener("scrollend", hold, { once: true });
		} else {
			setTimeout(hold, smooth ? 1000 : 0);
		}
	});
})();
