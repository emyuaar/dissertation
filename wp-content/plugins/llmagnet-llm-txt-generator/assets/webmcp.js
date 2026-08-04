/**
 * LLMagnet WebMCP loader (agent-readiness-spec Feature 4.1).
 *
 * Pure progressive enhancement: registers the site's public agent skills on
 * `navigator.modelContext` when (and only when) the browser exposes it.
 * Feature-detects BOTH proposed API shapes — per-tool `registerTool()` and
 * bulk `provideContext({ tools })` — in one adapter function so W3C spec
 * churn stays a one-file change. Zero console noise when unsupported.
 *
 * Config is injected by class-webmcp.php as `window.llmagnetWebmcp`:
 * { site, restBase, beaconUrl, postId, postType, woo, tools[], addToCart?, cartApi? }
 */
(function () {
	'use strict';

	var cfg = window.llmagnetWebmcp;
	var mc = typeof navigator !== 'undefined' ? navigator.modelContext : null;

	if (!cfg || !cfg.tools || !mc) {
		return; // No-op: feature off, broken config, or no WebMCP support.
	}

	/**
	 * Fire-and-forget usage beacon (logged as the "WebMCP Agent" bot).
	 */
	function beacon(toolId) {
		if (!cfg.beaconUrl) {
			return;
		}
		var payload = JSON.stringify({ tool: toolId, url: window.location.href });
		try {
			if (navigator.sendBeacon) {
				navigator.sendBeacon(
					cfg.beaconUrl,
					new Blob([payload], { type: 'application/json' })
				);
				return;
			}
		} catch (e) {
			/* fall through to fetch */
		}
		try {
			fetch(cfg.beaconUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: payload,
				keepalive: true,
			}).catch(function () {});
		} catch (e) {
			/* silent */
		}
	}

	/** WebMCP tool results are content blocks. */
	function ok(data) {
		return {
			content: [
				{ type: 'text', text: typeof data === 'string' ? data : JSON.stringify(data) },
			],
		};
	}

	function fail(message) {
		return { content: [{ type: 'text', text: message }], isError: true };
	}

	/**
	 * Build an execute() that proxies to one of the public REST routes.
	 */
	function restExecutor(tool) {
		return function (args) {
			beacon(tool.id);
			var url = tool.endpoint.url;
			var opts = { headers: { Accept: 'application/json' } };
			args = args || {};

			if (tool.endpoint.method === 'GET') {
				var pairs = [];
				for (var key in args) {
					if (
						Object.prototype.hasOwnProperty.call(args, key) &&
						args[key] !== undefined &&
						args[key] !== null
					) {
						pairs.push(
							encodeURIComponent(key) + '=' + encodeURIComponent(String(args[key]))
						);
					}
				}
				if (pairs.length) {
					url += (url.indexOf('?') >= 0 ? '&' : '?') + pairs.join('&');
				}
			} else {
				opts.method = tool.endpoint.method;
				opts.headers['Content-Type'] = 'application/json';
				opts.body = JSON.stringify(args);
			}

			return fetch(url, opts)
				.then(function (res) {
					return res
						.json()
						.catch(function () {
							return null;
						})
						.then(function (data) {
							if (res.ok) {
								return ok(data);
							}
							var message =
								data && data.message
									? data.message
									: 'Request failed (HTTP ' + res.status + ').';
							return fail(message);
						});
				})
				.catch(function () {
					return fail('Network error while contacting the site.');
				});
		};
	}

	/**
	 * Optional write tool: add a product to the cart via Woo's own Store API.
	 * Ships default OFF; only present when the site owner opted in.
	 */
	function cartTool() {
		return {
			name: 'add_to_cart',
			description:
				'Add a product to the shopping cart on this store. Pass the numeric product "id" (from search_products) and an optional "quantity" (default 1). Returns the updated cart.',
			inputSchema: {
				type: 'object',
				properties: {
					id: { type: 'integer', description: 'Product ID to add.' },
					quantity: { type: 'integer', description: 'Quantity to add (default 1).' },
				},
				required: ['id'],
				additionalProperties: false,
			},
			execute: function (args) {
				beacon('add_to_cart');
				args = args || {};
				return fetch(cfg.cartApi, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
					body: JSON.stringify({
						id: parseInt(args.id, 10) || 0,
						quantity: parseInt(args.quantity, 10) || 1,
					}),
				})
					.then(function (res) {
						return res
							.json()
							.catch(function () {
								return null;
							})
							.then(function (data) {
								if (res.ok) {
									return ok(data);
								}
								return fail(
									data && data.message
										? data.message
										: 'Could not add to cart (HTTP ' + res.status + ').'
								);
							});
					})
					.catch(function () {
						return fail('Network error while contacting the store.');
					});
			},
		};
	}

	var tools = [];
	for (var i = 0; i < cfg.tools.length; i++) {
		var t = cfg.tools[i];
		tools.push({
			name: t.id,
			description: t.description,
			inputSchema: t.input_schema,
			execute: restExecutor(t),
		});
	}
	if (cfg.addToCart && cfg.cartApi && cfg.woo) {
		tools.push(cartTool());
	}

	/**
	 * Shape adapter — the ONLY place that touches the WebMCP API surface.
	 * Supports both shapes of the moving W3C proposal:
	 * 1. navigator.modelContext.registerTool(tool)   — per-tool
	 * 2. navigator.modelContext.provideContext({tools}) — bulk
	 */
	function register(toolList) {
		try {
			if (typeof mc.registerTool === 'function') {
				for (var j = 0; j < toolList.length; j++) {
					mc.registerTool(toolList[j]);
				}
				return true;
			}
			if (typeof mc.provideContext === 'function') {
				mc.provideContext({ tools: toolList });
				return true;
			}
		} catch (e) {
			/* silent — agents see no tools, page is unaffected */
		}
		return false;
	}

	register(tools);
})();
