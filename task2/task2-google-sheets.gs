function doPost(e) {
  try {
    if (!e || !e.postData || !e.postData.contents) {
      return jsonResponse({
        status: 'error',
        message: 'Missing POST body.'
      }, 400);
    }

    const payload = JSON.parse(e.postData.contents);

    const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName('Orders');

    if (!sheet) {
      return jsonResponse({
        status: 'error',
        message: 'Sheet "Orders" was not found.'
      }, 500);
    }

    const row = [
      payload.received_at || '',
      payload.eshop_id || '',
      payload.order_code || '',
      payload.order_created || '',
      payload.customer_name || '',
      payload.customer_email || '',
      payload.total_to_pay || '',
      payload.currency || '',
      payload.payment_method || '',
      payload.shipping_method || '',
      payload.order_status || '',
      payload.paid || '',
      payload.items_summary || ''
    ];

    sheet.appendRow(row);

    return jsonResponse({
      status: 'success',
      message: 'Row appended successfully.'
    }, 200);

  } catch (error) {
    return jsonResponse({
      status: 'error',
      message: error.message
    }, 500);
  }
}

function jsonResponse(payload, statusCode) {
  const output = ContentService
    .createTextOutput(JSON.stringify({
      http_status: statusCode,
      ...payload
    }))
    .setMimeType(ContentService.MimeType.JSON);

  return output;
}
