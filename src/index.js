export default {
  async fetch(request, env, ctx) {
    const TARGET_UPSTREAM = env.TARGET_UPSTREAM;
    const USER_COOKIES = env.USER_COOKIES;

    if (!TARGET_UPSTREAM) {
      return new Response('Configuration Error: TARGET_UPSTREAM is missing', { status: 500 });
    }

    const TARGET_URL = `https://${TARGET_UPSTREAM}`;

    const url = new URL(request.url);
    const workerOrigin = url.origin;

    // Construct target URL
    const targetUrlObj = new URL(request.url);
    targetUrlObj.hostname = TARGET_UPSTREAM;
    targetUrlObj.protocol = 'https:';
    targetUrlObj.port = ''; // Ensure no port is carried over if developing locally

    // Prepare headers
    const newHeaders = new Headers(request.headers);
    newHeaders.set('Host', TARGET_UPSTREAM);
    newHeaders.set('Origin', TARGET_URL);
    newHeaders.set('Referer', TARGET_URL);

    // Inject cookies
    const existingCookies = newHeaders.get('Cookie') || '';
    const finalCookies = existingCookies && USER_COOKIES
      ? `${existingCookies}; ${USER_COOKIES}`
      : (USER_COOKIES || existingCookies);

    if (finalCookies) {
      newHeaders.set('Cookie', finalCookies);
    }

    // Remove headers that might cause issues
    newHeaders.delete('cf-connecting-ip');
    newHeaders.delete('cf-ipcountry');
    newHeaders.delete('cf-ray');
    newHeaders.delete('cf-visitor');

    try {
      const response = await fetch(targetUrlObj.toString(), {
        method: request.method,
        headers: newHeaders,
        body: request.method !== 'GET' && request.method !== 'HEAD' ? request.body : null,
        redirect: 'manual' // Handle redirects manually
      });

      // Handle Redirects
      if ([301, 302, 303, 307, 308].includes(response.status)) {
        const location = response.headers.get('Location');
        if (location) {
          const newResponse = new Response(response.body, response);
          // If location is absolute and points to target, rewrite to worker
          if (location.startsWith(TARGET_URL)) {
            newResponse.headers.set('Location', location.replace(TARGET_URL, workerOrigin));
          } else if (location.startsWith('http://' + TARGET_UPSTREAM)) {
             newResponse.headers.set('Location', location.replace('http://' + TARGET_UPSTREAM, workerOrigin));
          }
          // Relative locations are fine as they will be resolved against worker origin
          return cleanResponseHeaders(newResponse, workerOrigin);
        }
      }

      const contentType = response.headers.get('Content-Type') || '';

      // Handle HTML Content
      if (contentType.includes('text/html')) {
        return cleanResponseHeaders(
          new HTMLRewriter()
            .on('a', new AttributeRewriter('href', workerOrigin, TARGET_URL, TARGET_UPSTREAM))
            .on('img', new AttributeRewriter('src', workerOrigin, TARGET_URL, TARGET_UPSTREAM))
            .on('link', new AttributeRewriter('href', workerOrigin, TARGET_URL, TARGET_UPSTREAM))
            .on('script', new AttributeRewriter('src', workerOrigin, TARGET_URL, TARGET_UPSTREAM))
            .on('form', new AttributeRewriter('action', workerOrigin, TARGET_URL, TARGET_UPSTREAM))
            .on('source', new AttributeRewriter('src', workerOrigin, TARGET_URL, TARGET_UPSTREAM))
            .transform(response),
          workerOrigin
        );
      }

      // Handle JS/CSS/JSON (Text Replacement)
      if (contentType.includes('javascript') || contentType.includes('css') || contentType.includes('json')) {
        let text = await response.text();
        text = text.replaceAll(TARGET_URL, workerOrigin);
        // Also handle escaped versions often found in JSON or JS strings
        text = text.replaceAll(TARGET_URL.replace('/', '\\/'), workerOrigin.replace('/', '\\/'));

        return cleanResponseHeaders(new Response(text, response), workerOrigin);
      }

      // Stream everything else
      return cleanResponseHeaders(new Response(response.body, response), workerOrigin);

    } catch (e) {
      return new Response(`Proxy Error: ${e.message}`, { status: 500 });
    }
  },
};

// Helper to rewrite attributes
class AttributeRewriter {
  constructor(attributeName, workerOrigin, targetUrl, targetUpstream) {
    this.attributeName = attributeName;
    this.workerOrigin = workerOrigin;
    this.targetUrl = targetUrl;
    this.targetUpstream = targetUpstream;
  }

  element(element) {
    const attribute = element.getAttribute(this.attributeName);
    if (attribute) {
      if (attribute.startsWith(this.targetUrl)) {
        element.setAttribute(this.attributeName, attribute.replace(this.targetUrl, this.workerOrigin));
      } else if (attribute.startsWith('http://' + this.targetUpstream)) {
         element.setAttribute(this.attributeName, attribute.replace('http://' + this.targetUpstream, this.workerOrigin));
      }
    }
  }
}

// Helper to clean and rewrite response headers
function cleanResponseHeaders(response, workerOrigin) {
  const newHeaders = new Headers(response.headers);

  // Rewrite Set-Cookie domain
  const setCookie = newHeaders.get('Set-Cookie');
  if (setCookie) {
    const newSetCookie = setCookie
      .replace(/Domain=[^;]+;?/gi, '')
      .replace(/Secure/gi, 'Secure')
      .replace(/SameSite=Lax/gi, 'SameSite=None');

    newHeaders.set('Set-Cookie', newSetCookie);
  }

  // CORS headers
  newHeaders.set('Access-Control-Allow-Origin', '*');
  newHeaders.set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
  newHeaders.set('Access-Control-Allow-Headers', '*');
  newHeaders.set('Access-Control-Allow-Credentials', 'true');

  // Security Headers
  newHeaders.delete('Content-Security-Policy');
  newHeaders.delete('X-Frame-Options');

  return new Response(response.body, {
    status: response.status,
    statusText: response.statusText,
    headers: newHeaders
  });
}
