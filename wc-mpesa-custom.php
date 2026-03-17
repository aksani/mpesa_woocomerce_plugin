/* WooCommerce M-Pesa Admin Styles */

.wcmpesa-stats {
    display: flex;
    gap: 16px;
    margin: 16px 0 24px;
    flex-wrap: wrap;
}

.stat-box {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 16px 24px;
    min-width: 120px;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
}

.stat-box .stat-number {
    display: block;
    font-size: 28px;
    font-weight: 700;
    color: #1d2327;
    line-height: 1.2;
}

.stat-box .stat-label {
    display: block;
    font-size: 12px;
    color: #757575;
    margin-top: 4px;
    text-transform: uppercase;
    letter-spacing: .5px;
}

.stat-box.success { border-top: 4px solid #00a32a; }
.stat-box.pending { border-top: 4px solid #dba617; }
.stat-box.failed  { border-top: 4px solid #d63638; }
.stat-box.revenue { border-top: 4px solid #2271b1; }

/* Status badges */
.wcmpesa-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
}

.wcmpesa-badge--completed { background: #d1fae5; color: #065f46; }
.wcmpesa-badge--pending   { background: #fef3c7; color: #92400e; }
.wcmpesa-badge--failed    { background: #fee2e2; color: #991b1b; }

.wcmpesa-table { margin-top: 0; }
.wcmpesa-table th { font-weight: 600; }
