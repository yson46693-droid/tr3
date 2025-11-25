<?php
/**
 * Modals for managing company customers (add / edit / delete).
 */
$formAction = getRelativeUrl($dashboardScript);
?>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?php echo htmlspecialchars($formAction); ?>">
                <input type="hidden" name="page" value="customers">
                <input type="hidden" name="section" value="company">
                <input type="hidden" name="action" value="add_company_customer">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>إضافة عميل جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" name="phone" class="form-control" maxlength="20">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">البريد الإلكتروني</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <textarea name="address" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الرصيد الحالي</label>
                        <input type="number" name="balance" class="form-control" step="0.01" value="0">
                        <div class="form-text">أدخل قيمة موجبة للديون الحالية (إن وجدت).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ العميل</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?php echo htmlspecialchars($formAction); ?>">
                <input type="hidden" name="page" value="customers">
                <input type="hidden" name="section" value="company">
                <input type="hidden" name="action" value="edit_company_customer">
                <input type="hidden" name="customer_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>تعديل بيانات العميل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" name="phone" class="form-control" maxlength="20">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">البريد الإلكتروني</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <textarea name="address" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الرصيد الحالي</label>
                        <input type="number" name="balance" class="form-control" step="0.01">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Customer Modal -->
<div class="modal fade" id="deleteCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?php echo htmlspecialchars($formAction); ?>">
                <input type="hidden" name="page" value="customers">
                <input type="hidden" name="section" value="company">
                <input type="hidden" name="action" value="delete_company_customer">
                <input type="hidden" name="customer_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="bi bi-trash3 me-2"></i>حذف العميل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">هل أنت متأكد من حذف العميل <strong class="delete-customer-name">-</strong>؟ لا يمكن التراجع عن هذه العملية.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">تأكيد الحذف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var editModal = document.getElementById('editCustomerModal');
    var deleteModal = document.getElementById('deleteCustomerModal');

    editModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        if (!button) {
            return;
        }

        var customerId = button.getAttribute('data-customer-id') || '';
        var customerName = button.getAttribute('data-customer-name') || '';
        var customerPhone = button.getAttribute('data-customer-phone') || '';
        var customerAddress = button.getAttribute('data-customer-address') || '';
        var customerEmail = button.getAttribute('data-customer-email') || '';
        var customerBalance = button.getAttribute('data-customer-balance') || '';

        var modal = this;
        modal.querySelector('input[name="customer_id"]').value = customerId;
        modal.querySelector('input[name="name"]').value = customerName;
        modal.querySelector('input[name="phone"]').value = customerPhone;
        modal.querySelector('input[name="email"]').value = customerEmail;
        modal.querySelector('textarea[name="address"]').value = customerAddress;
        modal.querySelector('input[name="balance"]').value = customerBalance;
    });

    deleteModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        if (!button) {
            return;
        }
        var customerId = button.getAttribute('data-customer-id') || '';
        var customerName = button.getAttribute('data-customer-name') || '-';
        var modal = this;
        modal.querySelector('input[name="customer_id"]').value = customerId;
        modal.querySelector('.delete-customer-name').textContent = customerName;
    });
});
</script>

