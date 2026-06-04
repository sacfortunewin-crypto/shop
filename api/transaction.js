const {
  buildTransactionPayload,
  errorMessage,
  notifyUtmify,
  orderFromTransaction,
  pagouApiRequest,
  readJson,
  requireMethod,
  sendJson,
} = require("./_lib/pagou");

module.exports = async function handler(req, res) {
  if (!requireMethod(req, res, "POST")) return;

  let input;
  try {
    input = await readJson(req);
  } catch {
    sendJson(res, 400, { message: "JSON invalido." });
    return;
  }

  const payload = buildTransactionPayload(input, req, res);
  if (!payload) return;

  const response = await pagouApiRequest("POST", "/v2/transactions", payload);
  const body = response.body || {};

  if (response.status < 200 || response.status >= 300) {
    sendJson(res, response.status > 0 ? response.status : 502, {
      message: errorMessage(body),
      pagouStatus: response.status,
      requestId: body.requestId || null,
    });
    return;
  }

  const data = body.data && typeof body.data === "object" ? body.data : body;
  const transactionId = data.id || body.transactionId || null;
  const transaction = {
    ...data,
    id: transactionId || data.id,
    external_ref: data.external_ref || payload.external_ref,
    method: data.method || payload.method,
    amount: data.amount || payload.amount,
    currency: data.currency || payload.currency || "BRL",
    status: data.status || "pending",
    metadata: data.metadata || payload.metadata,
  };
  const utmify = await notifyUtmify(orderFromTransaction(transaction, req), transaction);

  if (payload.method === "pix") {
    const pix = data.pix && typeof data.pix === "object" ? data.pix : {};

    sendJson(res, 200, {
      transactionId,
      status: data.status || null,
      method: data.method || "pix",
      amount: data.amount || payload.amount,
      currency: data.currency || "BRL",
      requestId: body.requestId || null,
      utmify,
      pix: {
        qrCode: pix.qr_code || body.pixQrCode || null,
        qrCodeImage: pix.qr_code_image || body.pixQrCodeImage || null,
        expirationDate: pix.expiration_date || null,
        receiptUrl: pix.receipt_url || null,
      },
    });
    return;
  }

  sendJson(res, 200, {
    ...data,
    transactionId,
    requestId: body.requestId || null,
    utmify,
    transaction: data,
  });
};
