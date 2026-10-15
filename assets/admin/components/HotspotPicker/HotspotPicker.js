// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import Icon from 'sulu-admin-bundle/components/Icon';
import NumberField from 'sulu-admin-bundle/components/Number';
import {Requester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import userStore from 'sulu-admin-bundle/stores/userStore';
import type {FieldTypeProps} from 'sulu-admin-bundle/types';
import styles from './hotspotPicker.scss';

type HotspotData = {
    x: number,
    y: number,
    marker_type?: string,
    marker_color?: string,
    marker_icon?: ?string,
    trigger?: string,
};

type Props = FieldTypeProps<?(HotspotData | string)>;

function capitalize(s: ?string): string {
    if (!s) return 'Primary';
    return s.charAt(0).toUpperCase() + s.slice(1);
}

@observer
export default class HotspotPicker extends React.Component<Props> {
    @observable imageUrl: ?string = null;
    @observable imageLoading: boolean = false;
    @observable lastResolvedMediaId: ?(string | number) = null;
    @observable isDragging: boolean = false;
    @observable hoverCoords: ?{x: number, y: number} = null;

    canvasRef: {current: null | HTMLElement} = React.createRef();
    imageRef: {current: null | HTMLElement} = React.createRef();

    componentDidMount() {
        this.loadImage();
    }

    componentDidUpdate() {
        this.loadImage();
    }

    componentWillUnmount() {
        this.removeDragListeners();
    }

    get currentLocale(): string {
        const {formInspector} = this.props;
        if (formInspector && formInspector.locale && formInspector.locale.get()) {
            return String(formInspector.locale.get()).toLowerCase().split('_')[0];
        }

        const rawLoc = userStore.contentLocale || (userStore.user ? userStore.user.locale : null) || 'en';
        return String(rawLoc).toLowerCase().split('_')[0];
    }

    get parentImagePath(): string {
        const {dataPath, schemaOptions} = this.props;
        const imgProp = (schemaOptions && schemaOptions.image_property && schemaOptions.image_property.value)
            ? String(schemaOptions.image_property.value)
            : 'image';

        if (!dataPath) {
            return '/' + imgProp;
        }

        const normalized = dataPath.startsWith('/') ? dataPath : '/' + dataPath;

        // In a nested block item: e.g. /blocks/0/items/1/hotspot -> /blocks/0/image
        const lastItemsIndex = normalized.lastIndexOf('/items/');
        if (lastItemsIndex !== -1) {
            const blockPath = normalized.substring(0, lastItemsIndex);
            return `${blockPath}/${imgProp}`;
        }

        // Direct block property: e.g. /blocks/0/hotspot -> /blocks/0/image
        const parts = normalized.split('/');
        parts.pop();
        const parent = parts.join('/');
        return parent ? `${parent}/${imgProp}` : '/' + imgProp;
    }

    @action loadImage = () => {
        const {formInspector} = this.props;
        if (!formInspector) {
            return;
        }

        let rawMedia = null;
        try {
            rawMedia = formInspector.getValueByPath(this.parentImagePath);
        } catch (e) {
            // ignore
        }

        if (!rawMedia) {
            try {
                const parentPath = this.parentImagePath.substring(0, this.parentImagePath.lastIndexOf('/'));
                const parentObj = formInspector.getValueByPath(parentPath);
                if (parentObj && typeof parentObj === 'object') {
                    const imgProp = (this.props.schemaOptions && this.props.schemaOptions.image_property && this.props.schemaOptions.image_property.value)
                        ? String(this.props.schemaOptions.image_property.value)
                        : 'image';
                    rawMedia = parentObj[imgProp];
                }
            } catch (e) {
                // ignore
            }
        }

        if (!rawMedia) {
            if (this.imageUrl !== null && !this.lastResolvedMediaId) {
                this.imageUrl = null;
            }
            return;
        }

        if (typeof rawMedia === 'object' && rawMedia !== null) {
            if (rawMedia.thumbnails || rawMedia.formats || rawMedia.url) {
                const thumbs = rawMedia.thumbnails || rawMedia.formats || {};
                const url = thumbs['col-lg']
                    || thumbs['col-md']
                    || thumbs['sulu-400x400']
                    || thumbs['col-xl']
                    || rawMedia.url
                    || Object.values(thumbs)[0];

                if (url && typeof url === 'string') {
                    if (this.imageUrl !== url) {
                        this.imageUrl = url;
                    }
                    this.lastResolvedMediaId = rawMedia.id || null;
                    return;
                }
            }

            if (rawMedia.id) {
                this.fetchMediaById(rawMedia.id);
                return;
            }
        }

        if (typeof rawMedia === 'number' || (typeof rawMedia === 'string' && /^\d+$/.test(rawMedia))) {
            this.fetchMediaById(rawMedia);
        }
    };

    fetchMediaById = (id: number | string) => {
        if (this.imageLoading || this.lastResolvedMediaId === id) {
            return;
        }

        this.imageLoading = true;
        this.lastResolvedMediaId = id;

        const locale = this.currentLocale;
        Requester.get(`/admin/api/media/${id}?locale=${locale}`)
            .then(action((response) => {
                this.imageLoading = false;
                if (!response) {
                    return;
                }
                const thumbs = response.thumbnails || response.formats || {};
                const url = thumbs['col-lg']
                    || thumbs['col-md']
                    || thumbs['sulu-400x400']
                    || thumbs['col-xl']
                    || response.url
                    || Object.values(thumbs)[0];

                if (url && typeof url === 'string') {
                    this.imageUrl = url;
                }
            }))
            .catch(action(() => {
                this.imageLoading = false;
                this.imageUrl = null;
            }));
    };

    get currentValue(): HotspotData {
        const {value} = this.props;
        let data: Object = {};

        if (typeof value === 'string' && value.trim()) {
            try {
                data = JSON.parse(value);
            } catch (e) {
                // ignore
            }
        } else if (value && typeof value === 'object') {
            data = toJS(value);
        }

        const rawX = data.x !== undefined && data.x !== null ? parseFloat(data.x) : 50.0;
        const rawY = data.y !== undefined && data.y !== null ? parseFloat(data.y) : 50.0;

        const x = isNaN(rawX) ? 50.0 : Math.round(Math.max(0.0, Math.min(100.0, rawX)) * 10) / 10;
        const y = isNaN(rawY) ? 50.0 : Math.round(Math.max(0.0, Math.min(100.0, rawY)) * 10) / 10;

        return {
            ...data,
            x,
            y,
        };
    }

    emitChange = (updates: $Shape<HotspotData>, finish: boolean = false) => {
        const {onChange, onFinish} = this.props;
        const current = this.currentValue;
        const updated: HotspotData = {
            ...current,
            ...updates,
        };

        if (onChange) {
            onChange(updated);
        }
        if (finish && onFinish) {
            onFinish();
        }
    };

    handleCanvasMouseDown = (e: SyntheticMouseEvent<HTMLElement>) => {
        e.preventDefault();
        action(() => {
            this.isDragging = true;
        })();
        this.updateCoordsFromMouseEvent(e.nativeEvent);

        window.addEventListener('mousemove', this.handleWindowMouseMove);
        window.addEventListener('mouseup', this.handleWindowMouseUp);
    };

    handleWindowMouseMove = (e: MouseEvent) => {
        if (!this.isDragging) return;
        this.updateCoordsFromMouseEvent(e);
    };

    handleWindowMouseUp = (e: MouseEvent) => {
        if (!this.isDragging) return;
        action(() => {
            this.isDragging = false;
        })();
        this.removeDragListeners();
        this.updateCoordsFromMouseEvent(e);

        const {onFinish} = this.props;
        if (onFinish) {
            onFinish();
        }
    };

    removeDragListeners = () => {
        window.removeEventListener('mousemove', this.handleWindowMouseMove);
        window.removeEventListener('mouseup', this.handleWindowMouseUp);
    };

    updateCoordsFromMouseEvent = (e: MouseEvent) => {
        const target = this.imageRef.current || this.canvasRef.current;
        if (!target) return;

        const rect = target.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) return;

        let x = ((e.clientX - rect.left) / rect.width) * 100;
        let y = ((e.clientY - rect.top) / rect.height) * 100;

        x = Math.max(0.0, Math.min(100.0, Math.round(x * 10) / 10));
        y = Math.max(0.0, Math.min(100.0, Math.round(y * 10) / 10));

        this.emitChange({x, y}, false);
    };

    handleCanvasHoverMove = (e: SyntheticMouseEvent<HTMLElement>) => {
        if (this.isDragging) return;
        const target = this.imageRef.current || this.canvasRef.current;
        if (!target) return;

        const rect = target.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) return;

        let x = ((e.clientX - rect.left) / rect.width) * 100;
        let y = ((e.clientY - rect.top) / rect.height) * 100;

        x = Math.max(0.0, Math.min(100.0, Math.round(x * 10) / 10));
        y = Math.max(0.0, Math.min(100.0, Math.round(y * 10) / 10));

        action(() => {
            this.hoverCoords = {x, y};
        })();
    };

    handleCanvasHoverLeave = () => {
        action(() => {
            this.hoverCoords = null;
        })();
    };

    handleResetCenter = () => {
        this.emitChange({x: 50.0, y: 50.0}, true);
    };

    handleManualXChange = (val: ?number) => {
        const num = val !== undefined && val !== null && !isNaN(val) ? val : 50.0;
        const clamped = Math.max(0.0, Math.min(100.0, Math.round(num * 10) / 10));
        this.emitChange({x: clamped}, true);
    };

    handleManualYChange = (val: ?number) => {
        const num = val !== undefined && val !== null && !isNaN(val) ? val : 50.0;
        const clamped = Math.max(0.0, Math.min(100.0, Math.round(num * 10) / 10));
        this.emitChange({y: clamped}, true);
    };

    render() {
        const {x, y, marker_color} = this.currentValue;
        const activeMarkerColor = marker_color || 'primary';
        const colorClass = styles['color' + capitalize(activeMarkerColor)] || styles.colorPrimary;

        return (
            <div className={styles.container}>
                {/* 1. Interactive Canvas */}
                <div
                    className={`${styles.canvasWrapper} ${this.isDragging ? styles.canvasDragging : ''}`}
                    onMouseDown={this.handleCanvasMouseDown}
                    onMouseLeave={this.handleCanvasHoverLeave}
                    onMouseMove={this.handleCanvasHoverMove}
                    ref={this.canvasRef}
                >
                    {/* Floating canvas actions (Refresh, Center) */}
                    <div className={styles.canvasActions}>
                        <button
                            className={styles.canvasActionBtn}
                            onClick={this.loadImage}
                            title={translate('sulu_block_suite.hotspot_picker.refresh_image') || 'Refresh'}
                            type="button"
                        >
                            <Icon name="su-sync" />
                        </button>
                        <button
                            className={styles.canvasActionBtn}
                            onClick={this.handleResetCenter}
                            title={translate('sulu_block_suite.hotspot_picker.center') || 'Center (50%, 50%)'}
                            type="button"
                        >
                            <Icon name="su-focus" />
                        </button>
                    </div>

                    {/* Live hover coordinates badge */}
                    {this.hoverCoords && (
                        <div className={styles.canvasHoverBadge}>
                            {this.hoverCoords.x.toFixed(1)}%, {this.hoverCoords.y.toFixed(1)}%
                        </div>
                    )}

                    {this.imageUrl ? (
                        <div className={styles.imageContainer}>
                            <img
                                alt="Hotspot Target"
                                className={styles.canvasImage}
                                ref={this.imageRef}
                                src={this.imageUrl}
                            />

                            {/* Active crosshair guides */}
                            {this.isDragging && (
                                <React.Fragment>
                                    <div className={styles.crosshairX} style={{left: `${x}%`}} />
                                    <div className={styles.crosshairY} style={{top: `${y}%`}} />
                                </React.Fragment>
                            )}

                            {/* Marker Pin with Radar Pulse */}
                            <div
                                className={`${styles.marker} ${colorClass} ${this.isDragging ? styles.markerActive : ''}`}
                                style={{left: `${x}%`, top: `${y}%`}}
                            >
                                <div className={styles.pulseRing} />
                                <div className={styles.markerInner}>
                                    <span style={{display: 'inline-block', width: 6, height: 6, borderRadius: '50%', backgroundColor: '#ffffff'}} />
                                </div>
                                <div className={styles.markerTooltip}>
                                    {x.toFixed(1)}%, {y.toFixed(1)}%
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className={styles.placeholderArea}>
                            <div className={styles.placeholderIcon}>
                                <Icon name="su-image" />
                            </div>
                            <div className={styles.placeholderText}>
                                {translate('sulu_block_suite.hotspot_picker.no_image')
                                    || 'No background image detected. Click canvas grid or enter coordinates directly.'}
                            </div>

                            <div
                                className={`${styles.marker} ${colorClass}`}
                                style={{left: `${x}%`, top: `${y}%`}}
                            >
                                <div className={styles.pulseRing} />
                                <div className={styles.markerInner}>
                                    <span style={{display: 'inline-block', width: 6, height: 6, borderRadius: '50%', backgroundColor: '#ffffff'}} />
                                </div>
                                <div className={styles.markerTooltip}>
                                    {x.toFixed(1)}%, {y.toFixed(1)}%
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                {/* 2. Precision Coordinate Inputs */}
                <div className={styles.controlsArea}>
                    <div className={styles.coordsGrid}>
                        <div className={styles.fieldGroup}>
                            <label className={styles.fieldLabel}>
                                {translate('sulu_block_suite.hotspot_picker.x') || 'Horizontal (X)'} (%)
                            </label>
                            <NumberField
                                max={100}
                                min={0}
                                onChange={this.handleManualXChange}
                                step={0.5}
                                value={x}
                            />
                        </div>

                        <div className={styles.fieldGroup}>
                            <label className={styles.fieldLabel}>
                                {translate('sulu_block_suite.hotspot_picker.y') || 'Vertical (Y)'} (%)
                            </label>
                            <NumberField
                                max={100}
                                min={0}
                                onChange={this.handleManualYChange}
                                step={0.5}
                                value={y}
                            />
                        </div>
                    </div>
                </div>
            </div>
        );
    }
}
