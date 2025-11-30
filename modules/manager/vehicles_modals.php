<!-- Modal التعديل السريع -->
<div class="modal fade" id="editVehicleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>تعديل السيارة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editVehicleForm">
                <input type="hidden" name="action" value="update_vehicle">
                <input type="hidden" name="vehicle_id" id="editVehicleId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">رقم السيارة <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="vehicle_number" id="editVehicleNumber" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع السيارة</label>
                        <input type="text" class="form-control" name="vehicle_type" id="editVehicleType">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الموديل</label>
                        <input type="text" class="form-control" name="model" id="editVehicleModel">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">السنة</label>
                        <input type="number" class="form-control" name="year" id="editVehicleYear" 
                               min="2000" max="<?php echo date('Y'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المندوب (السائق)</label>
                        <select class="form-select" name="driver_id" id="editVehicleDriver">
                            <option value="">لا يوجد</option>
                            <?php foreach ($salesReps as $rep): ?>
                                <option value="<?php echo $rep['id']; ?>">
                                    <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الحالة</label>
                        <select class="form-select" name="status" id="editVehicleStatus">
                            <option value="active">نشطة</option>
                            <option value="inactive">غير نشطة</option>
                            <option value="maintenance">صيانة</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" id="editVehicleNotes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-save me-2"></i>حفظ التغييرات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تأكيد الحذف -->
<div class="modal fade" id="deleteVehicleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>تأكيد الحذف</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>تحذير:</strong> سيتم حذف السيارة بشكل نهائي ولا يمكن التراجع عن هذه العملية.
                </div>
                <p>هل أنت متأكد من حذف السيارة رقم: <strong id="deleteVehicleNumber"></strong>؟</p>
                <p class="text-muted small">ملاحظة: لا يمكن حذف السيارة إذا كانت مرتبطة بمخزون أو طلبات أو نقلات.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <form method="POST" id="deleteVehicleForm" style="display: inline;">
                    <input type="hidden" name="action" value="delete_vehicle">
                    <input type="hidden" name="vehicle_id" id="deleteVehicleId">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-2"></i>حذف
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function openEditModal(vehicleData) {
    document.getElementById('editVehicleId').value = vehicleData.id;
    document.getElementById('editVehicleNumber').value = vehicleData.vehicle_number || '';
    document.getElementById('editVehicleType').value = vehicleData.vehicle_type || '';
    document.getElementById('editVehicleModel').value = vehicleData.model || '';
    
    // معالجة السنة
    if (vehicleData.year) {
        const yearValue = typeof vehicleData.year === 'string' ? 
            new Date(vehicleData.year + '-01-01').getFullYear() : 
            vehicleData.year;
        document.getElementById('editVehicleYear').value = yearValue;
    } else {
        document.getElementById('editVehicleYear').value = '';
    }
    
    document.getElementById('editVehicleDriver').value = vehicleData.driver_id || '';
    document.getElementById('editVehicleStatus').value = vehicleData.status || 'active';
    document.getElementById('editVehicleNotes').value = vehicleData.notes || '';
    
    // فتح الـ modal
    const modal = new bootstrap.Modal(document.getElementById('editVehicleModal'));
    modal.show();
}

function confirmDelete(vehicleId, vehicleNumber) {
    document.getElementById('deleteVehicleId').value = vehicleId;
    document.getElementById('deleteVehicleNumber').textContent = vehicleNumber;
    
    // فتح الـ modal
    const modal = new bootstrap.Modal(document.getElementById('deleteVehicleModal'));
    modal.show();
}
</script>

