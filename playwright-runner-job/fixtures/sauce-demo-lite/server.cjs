const http = require('node:http')
const { URL, URLSearchParams } = require('node:url')

const HOST = process.env.BENCHMARK_FIXTURE_HOST || '127.0.0.1'
const PORT = Number.parseInt(process.env.BENCHMARK_FIXTURE_PORT || '4177', 10)
const AUTH_COOKIE = 'sauce_demo_auth'
const CART_COOKIE = 'sauce_demo_cart'

const PRODUCTS = [
  {
    slug: 'sauce-labs-backpack',
    name: 'Sauce Labs Backpack',
    price: 29.99,
    description: 'Carry all the things with the streamlined Sly Pack.',
  },
  {
    slug: 'sauce-labs-bike-light',
    name: 'Sauce Labs Bike Light',
    price: 9.99,
    description: 'A red light that turns on with a single click.',
  },
  {
    slug: 'test.allthethings()-t-shirt-(red)',
    name: 'Test.allTheThings() T-Shirt (Red)',
    price: 15.99,
    description: 'A classic Sauce Labs tee for debugging in style.',
  },
]

function layout(body, title = 'Swag Labs') {
  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>${title}</title>
  <style>
    body { font-family: sans-serif; margin: 2rem; background: #f6f7fb; color: #102a43; }
    .shell { max-width: 880px; margin: 0 auto; background: white; padding: 2rem; border-radius: 16px; box-shadow: 0 10px 30px rgba(16,42,67,.08); }
    .stack { display: grid; gap: 1rem; }
    .toolbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
    .toolbar-actions { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
    input, button, select { font: inherit; padding: .75rem 1rem; border-radius: 10px; border: 1px solid #bcccdc; width: 100%; }
    button { background: #127fbf; color: white; border: none; cursor: pointer; }
    .inline-form { margin: 0; }
    .inline-form button { width: auto; }
    .error-message-container { background: #fde8e8; color: #9b1c1c; padding: .75rem 1rem; border-radius: 10px; display: grid; gap: .5rem; }
    .products, .title { font-size: 2rem; font-weight: 700; }
    .cart-badge { display:inline-block; min-width:1.5rem; text-align:center; padding:.15rem .4rem; border-radius:999px; background:#127fbf; color:white; }
    .inventory_list, .cart_list { display: grid; gap: 1rem; }
    .inventory_item, .cart_item, .detail-card { border: 1px solid #d9e2ec; border-radius: 12px; padding: 1rem; display: grid; gap: .75rem; }
    .inventory_item_name, .detail-title { font-size: 1.2rem; font-weight: 700; }
    .price { color: #486581; font-weight: 700; }
    a { color: #127fbf; text-decoration: none; }
    .sort-label { font-weight: 600; color: #486581; }
  </style>
</head>
<body>
  <main class="shell">${body}</main>
</body>
</html>`
}

function parseCookies(req) {
  const header = req.headers.cookie || ''
  return header.split(';').reduce((cookies, rawPart) => {
    const [rawKey, ...rawValueParts] = rawPart.trim().split('=')
    if (!rawKey) {
      return cookies
    }

    cookies[rawKey] = decodeURIComponent(rawValueParts.join('=') || '')
    return cookies
  }, {})
}

function serializeCookie(name, value, options = {}) {
  const parts = [`${name}=${encodeURIComponent(value)}`, 'Path=/', 'HttpOnly']
  if (options.maxAge !== undefined) {
    parts.push(`Max-Age=${options.maxAge}`)
  }
  return parts.join('; ')
}

function buildSetCookieHeaders(headers) {
  return headers.length > 0 ? { 'Set-Cookie': headers } : {}
}

function cartFromCookies(req) {
  const cookies = parseCookies(req)
  const entries = cookies[CART_COOKIE] ? cookies[CART_COOKIE].split(',').filter(Boolean) : []
  return new Set(entries)
}

function isAuthenticated(req) {
  return parseCookies(req)[AUTH_COOKIE] === '1'
}

function loginPage(errorMessage = '') {
  const errorBlock = errorMessage
    ? `<div class="error-message-container error"><h3 data-test="error">${errorMessage}</h3><button type="button" class="error-button">x</button></div>`
    : ''

  return layout(`
    <div class="stack">
      <h1 class="login_logo">Swag Labs</h1>
      ${errorBlock}
      <form method="post" action="/login" class="stack">
        <label>Username
          <input
            name="user-name"
            id="user-name"
            data-test="username"
            autocomplete="username"
          />
        </label>
        <label>Password
          <input
            name="password"
            id="password"
            data-test="password"
            type="password"
            autocomplete="current-password"
          />
        </label>
        <button type="submit" id="login-button" data-test="login-button">Login</button>
      </form>
    </div>
  `)
}

function cartLink(cartItems) {
  return `
    <a href="/cart.html" data-test="shopping-cart-link">
      Cart ${cartItems.size > 0 ? `<span class="cart-badge" data-test="shopping-cart-badge">${cartItems.size}</span>` : ''}
    </a>
  `
}

function sortProducts(products, sortValue) {
  const sorted = [...products]
  switch (sortValue) {
    case 'za':
      sorted.sort((left, right) => right.name.localeCompare(left.name))
      break
    case 'lohi':
      sorted.sort((left, right) => left.price - right.price)
      break
    case 'hilo':
      sorted.sort((left, right) => right.price - left.price)
      break
    case 'az':
    default:
      sorted.sort((left, right) => left.name.localeCompare(right.name))
      break
  }
  return sorted
}

function sortLabel(sortValue) {
  switch (sortValue) {
    case 'za':
      return 'Name (Z to A)'
    case 'lohi':
      return 'Price (low to high)'
    case 'hilo':
      return 'Price (high to low)'
    case 'az':
    default:
      return 'Name (A to Z)'
  }
}

function productAction(product, cartItems, redirectTo) {
  const inCart = cartItems.has(product.slug)
  const actionPath = inCart ? '/cart/remove' : '/cart/add'
  const buttonTestId = inCart ? `remove-${product.slug}` : `add-to-cart-${product.slug}`
  const buttonText = inCart ? 'Remove' : 'Add to cart'

  return `
    <form method="post" action="${actionPath}" class="inline-form">
      <input type="hidden" name="product" value="${product.slug}" />
      <input type="hidden" name="redirect_to" value="${redirectTo}" />
      <button type="submit" data-test="${buttonTestId}">${buttonText}</button>
    </form>
  `
}

function inventoryPage(cartItems, sortValue = 'az') {
  const products = sortProducts(PRODUCTS, sortValue)

  return layout(`
    <div class="stack" data-test="inventory-container">
      <header class="toolbar">
        <div class="stack" style="gap:.25rem">
          <div class="app_logo">Swag Labs</div>
          <div class="products title" data-test="title">Products</div>
        </div>
        <div class="toolbar-actions">
          <span class="sort-label" data-test="product-sort-container">${sortLabel(sortValue)}</span>
          ${cartLink(cartItems)}
        </div>
      </header>
      <section class="inventory_list">
        ${products.map((product) => `
          <article class="inventory_item" data-test="inventory-item">
            <a href="/inventory-item.html?id=${product.slug}" class="inventory_item_name" data-test="item-${product.slug}-title">${product.name}</a>
            <div class="price" data-test="item-${product.slug}-price">$${product.price.toFixed(2)}</div>
            <p>${product.description}</p>
            ${productAction(product, cartItems, `/inventory.html?sort=${sortValue}`)}
          </article>
        `).join('')}
      </section>
    </div>
  `, 'Products')
}

function cartPage(cartItems) {
  const cartProducts = PRODUCTS.filter((product) => cartItems.has(product.slug))

  return layout(`
    <div class="stack">
      <header class="toolbar">
        <div class="stack" style="gap:.25rem">
          <div class="app_logo">Swag Labs</div>
          <div class="title">Your Cart</div>
        </div>
        <div>${cartLink(cartItems)}</div>
      </header>
      <a href="/inventory.html">Continue Shopping</a>
      <section class="cart_list">
        ${cartProducts.length > 0
          ? cartProducts.map((product) => `
            <article class="cart_item" data-test="inventory-item">
              <h2 data-test="item-${product.slug}-title">${product.name}</h2>
              <div class="price">$${product.price.toFixed(2)}</div>
              ${productAction(product, cartItems, '/cart.html')}
            </article>
          `).join('')
          : `<p data-test="empty-cart">Your cart is empty.</p>`}
      </section>
    </div>
  `, 'Your Cart')
}

function productDetailPage(product, cartItems) {
  return layout(`
    <div class="stack detail-card">
      <div class="toolbar">
        <a href="/inventory.html" data-test="back-to-products">Back to products</a>
        <div>${cartLink(cartItems)}</div>
      </div>
      <div class="detail-title" data-test="inventory-item-name">${product.name}</div>
      <div class="price" data-test="inventory-item-price">$${product.price.toFixed(2)}</div>
      <p>${product.description}</p>
      ${productAction(product, cartItems, `/inventory-item.html?id=${product.slug}`)}
    </div>
  `, product.name)
}

function sendHtml(res, html, status = 200, headers = {}) {
  res.writeHead(status, {
    'Content-Type': 'text/html; charset=utf-8',
    ...headers,
  })
  res.end(html)
}

function redirect(res, location, cookieHeaders = []) {
  res.writeHead(302, {
    Location: location,
    ...buildSetCookieHeaders(cookieHeaders),
  })
  res.end()
}

function parseBody(req) {
  return new Promise((resolve, reject) => {
    let body = ''
    req.on('data', (chunk) => {
      body += chunk.toString()
    })
    req.on('end', () => {
      resolve(new URLSearchParams(body))
    })
    req.on('error', reject)
  })
}

function normalizeRedirectTarget(target, fallback) {
  if (typeof target !== 'string' || target.trim() === '') {
    return fallback
  }

  return target.startsWith('/') ? target : fallback
}

function requireAuth(req, res) {
  if (isAuthenticated(req)) {
    return false
  }

  redirect(res, '/login')
  return true
}

const server = http.createServer(async (req, res) => {
  if (!req.url) {
    sendHtml(res, loginPage(), 400)
    return
  }

  const requestUrl = new URL(req.url, `http://${HOST}:${PORT}`)

  if (req.method === 'GET' && (requestUrl.pathname === '/' || requestUrl.pathname === '/login')) {
    sendHtml(res, loginPage())
    return
  }

  if (req.method === 'GET' && requestUrl.pathname === '/inventory.html') {
    if (requireAuth(req, res)) {
      return
    }

    sendHtml(res, inventoryPage(cartFromCookies(req), requestUrl.searchParams.get('sort') || 'az'))
    return
  }

  if (req.method === 'GET' && requestUrl.pathname === '/cart.html') {
    if (requireAuth(req, res)) {
      return
    }

    sendHtml(res, cartPage(cartFromCookies(req)))
    return
  }

  if (req.method === 'GET' && requestUrl.pathname === '/inventory-item.html') {
    if (requireAuth(req, res)) {
      return
    }

    const product = PRODUCTS.find((entry) => entry.slug === requestUrl.searchParams.get('id')) || PRODUCTS[0]
    sendHtml(res, productDetailPage(product, cartFromCookies(req)))
    return
  }

  if (req.method === 'POST' && requestUrl.pathname === '/login') {
    const params = await parseBody(req)
    const username = params.get('user-name') ?? ''
    const password = params.get('password') ?? ''

    if (username.trim() === '') {
      sendHtml(res, loginPage('Username is required'))
      return
    }

    if (password.trim() === '') {
      sendHtml(res, loginPage('Password is required'))
      return
    }

    if (username === 'locked_out_user' && password === 'secret_sauce') {
      sendHtml(res, loginPage('Sorry, this user has been locked out.'))
      return
    }

    if (username === 'standard_user' && password === 'secret_sauce') {
      redirect(res, '/inventory.html', [
        serializeCookie(AUTH_COOKIE, '1', { maxAge: 3600 }),
        serializeCookie(CART_COOKIE, '', { maxAge: 0 }),
      ])
      return
    }

    sendHtml(res, loginPage('Username and password do not match any user in this service'))
    return
  }

  if (req.method === 'POST' && (requestUrl.pathname === '/cart/add' || requestUrl.pathname === '/cart/remove')) {
    if (requireAuth(req, res)) {
      return
    }

    const params = await parseBody(req)
    const product = PRODUCTS.find((entry) => entry.slug === (params.get('product') ?? '')) || PRODUCTS[0]
    const redirectTo = normalizeRedirectTarget(params.get('redirect_to'), '/inventory.html')
    const cartItems = cartFromCookies(req)

    if (requestUrl.pathname === '/cart/add') {
      cartItems.add(product.slug)
    } else {
      cartItems.delete(product.slug)
    }

    redirect(res, redirectTo, [
      serializeCookie(CART_COOKIE, Array.from(cartItems).join(','), { maxAge: 3600 }),
    ])
    return
  }

  sendHtml(res, layout('<h1>Not Found</h1>', 'Not Found'), 404)
})

server.listen(PORT, HOST, () => {
  console.log(`[sauce-demo-lite] listening on http://${HOST}:${PORT}`)
})
