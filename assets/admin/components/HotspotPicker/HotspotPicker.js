// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import Button from 'sulu-admin-bundle/components/Button';
import Icon from 'sulu-admin-bundle/components/Icon';
import NumberField from 'sulu-admin-bundle/components/Number';
import SingleSelect from 'sulu-admin-bundle/components/SingleSelect';
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

    canvasRef: {current: null | HTMLElement} = React.createRef();
    imageRef: {current: null | HTMLElement} = React.createRef();
    isDragging: boolean = false;

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
        if (this.lastResolvedMediaId === id && this.imageUrl) {
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
            x,
            y,
            marker_type: data.marker_type || 'pulse',
            marker_color: data.marker_color || 'primary',
            marker_icon: data.marker_icon || null,
            trigger: data.trigger || 'click',
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
        this.isDragging = true;
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
        this.isDragging = false;
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

    handleMarkerTypeChange = (val: string) => {
        this.emitChange({marker_type: val}, true);
    };

    handleMarkerColorChange = (val: string) => {
        this.emitChange({marker_color: val}, true);
    };

    handleTriggerChange = (val: string) => {
        this.emitChange({trigger: val}, true);
    };

    renderMarkerIcon(type: string) {
        switch (type) {
            case 'plus':
                return '+';
            case 'number':
                return '1';
            case 'dot':
                return '•';
            case 'pin':
                return <Icon name="su-location" />;
            case 'pulse':
            default:
                return null;
        }
    }

    render() {
        const {x, y, marker_type, marker_color, trigger} = this.currentValue;
        const activeMarkerType = marker_type || 'pulse';
        const activeMarkerColor = marker_color || 'primary';
        const activeTrigger = trigger || 'click';

        const colorClass = styles['color' + capitalize(activeMarkerColor)] || styles.colorPrimary;

        return (
            <div className={styles.container}>
                <div className={styles.header}>
                    <div className={styles.headerTitle}>
                        <Icon name="su-location" />
                        <span>{translate('sulu_block_suite.hotspot_picker.title') || 'Interactive Hotspot Marker'}</span>
                        <span className={styles.coordBadge}>
                            X: {x.toFixed(1)}% | Y: {y.toFixed(1)}%
                        </span>
                    </div>
                    <div className={styles.headerActions}>
                        <Button
                            onClick={this.loadImage}
                            size="small"
                            skin="secondary"
                        >
                            <Icon name="su-sync" />
                            {translate('sulu_block_suite.hotspot_picker.refresh_image') || 'Refresh Image'}
                        </Button>
                        <Button
                            onClick={this.handleResetCenter}
                            size="small"
                            skin="secondary"
                        >
                            {translate('sulu_block_suite.hotspot_picker.center') || 'Center (50%, 50%)'}
                        </Button>
                    </div>
                </div>

                <div
                    className={styles.canvasWrapper}
                    onMouseDown={this.handleCanvasMouseDown}
                    ref={this.canvasRef}
                >
                    {this.imageUrl ? (
                        <div style={{position: 'relative', display: 'inline-block', lineHeight: 0}}>
                            <img
                                alt="Hotspot Target"
                                className={styles.canvasImage}
                                ref={this.imageRef}
                                src={this.imageUrl}
                            />
                            <div
                                className={`${styles.marker} ${colorClass}`}
                                style={{left: `${x}%`, top: `${y}%`}}
                            >
                                <div className={styles.pulseRing} />
                                <div className={styles.markerInner}>
                                    {this.renderMarkerIcon(activeMarkerType)}
                                </div>
                                <div className={styles.markerTooltip}>
                                    {x.toFixed(1)}% , {y.toFixed(1)}%
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className={styles.placeholderArea} style={{position: 'relative'}}>
                            <div className={styles.placeholderIcon}>
                                <Icon name="su-image" />
                            </div>
                            <div className={styles.placeholderText}>
                                {translate('sulu_block_suite.hotspot_picker.no_image')
                                    || 'No background image detected. Click on the canvas grid or adjust coordinates below.'}
                            </div>
                            <div className={styles.placeholderHint}>
                                {translate('sulu_block_suite.hotspot_picker.hint')
                                    || 'Hotspot will automatically align when the background image is selected.'}
                            </div>

                            <div
                                className={`${styles.marker} ${colorClass}`}
                                style={{left: `${x}%`, top: `${y}%`}}
                            >
                                <div className={styles.pulseRing} />
                                <div className={styles.markerInner}>
                                    {this.renderMarkerIcon(activeMarkerType)}
                                </div>
                                <div className={styles.markerTooltip}>
                                    {x.toFixed(1)}% , {y.toFixed(1)}%
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                <div className={styles.controlsArea}>
                    <div className={styles.controlsGrid}>
                        <div className={styles.fieldGroup}>
                            <label className={styles.fieldLabel}>
                                {translate('sulu_block_suite.hotspot_picker.x') || 'X Position (%)'}
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
                                {translate('sulu_block_suite.hotspot_picker.y') || 'Y Position (%)'}
                            </label>
                            <NumberField
                                max={100}
                                min={0}
                                onChange={this.handleManualYChange}
                                step={0.5}
                                value={y}
                            />
                        </div>

                        <div className={styles.fieldGroup}>
                            <label className={styles.fieldLabel}>
                                {translate('sulu_block_suite.hotspot_picker.marker_type') || 'Marker Style'}
                            </label>
                            <SingleSelect onChange={this.handleMarkerTypeChange} value={activeMarkerType}>
                                <SingleSelect.Option value="pulse">
                                    {translate('sulu_block_suite.hotspot_picker.type_pulse') || 'Pulse Ring'}
                                </SingleSelect.Option>
                                <SingleSelect.Option value="plus">
                                    {translate('sulu_block_suite.hotspot_picker.type_plus') || 'Plus (+)'}
                                </SingleSelect.Option>
                                <SingleSelect.Option value="number">
                                    {translate('sulu_block_suite.hotspot_picker.type_number') || 'Number (1)'}
                                </SingleSelect.Option>
                                <SingleSelect.Option value="dot">
                                    {translate('sulu_block_suite.hotspot_picker.type_dot') || 'Solid Dot'}
                                </SingleSelect.Option>
                                <SingleSelect.Option value="pin">
                                    {translate('sulu_block_suite.hotspot_picker.type_pin') || 'Map Pin'}
                                </SingleSelect.Option>
                            </SingleSelect>
                        </div>

                        <div className={styles.fieldGroup}>
                            <label className={styles.fieldLabel}>
                                {translate('sulu_block_suite.hotspot_picker.marker_color') || 'Marker Color'}
                            </label>
                            <SingleSelect onChange={this.handleMarkerColorChange} value={activeMarkerColor}>
                                <SingleSelect.Option value="primary">
                                    {translate('sulu_block_suite.hotspot_picker.color_primary') || 'Primary (Blue)'}
                                </SingleSelect.Option>
                                <SingleSelect.Option value="dark">
                                    {translate('sulu_block_suite.hotspot_picker.color_dark') || 'Dark (Neutral)'}
                                </SingleSelect.Option>
                                <SingleSelect.Option value="light">
                                    {translate('sulu_block_suite.hotspot_picker.color_light') || 'Light (White)'}
                                </SingleSelect.Option>
                                <SingleSelect.Option value="accent">
                                    {translate('sulu_block_suite.hotspot_picker.color_accent') || 'Accent (Amber)'}
                                </SingleSelect.Option>
                                <SingleSelect.Option value="success">
                                    {translate('sulu_block_suite.hotspot_picker.color_success') || 'Success (Green)'}
                                </SingleSelect.Option>
                                <SingleSelect.Option value="danger">
                                    {translate('sulu_block_suite.hotspot_picker.color_danger') || 'Danger (Red)'}
                                </SingleSelect.Option>
                            </SingleSelect>
                        </div>

                        <div className={styles.fieldGroup}>
                            <label className={styles.fieldLabel}>
                                {translate('sulu_block_suite.hotspot_picker.trigger') || 'Trigger'}
                            </label>
                            <SingleSelect onChange={this.handleTriggerChange} value={activeTrigger}>
                                <SingleSelect.Option value="click">
                                    {translate('sulu_block_suite.hotspot_picker.trigger_click') || 'On Click'}
                                </SingleSelect.Option>
                                <SingleSelect.Option value="hover">
                                    {translate('sulu_block_suite.hotspot_picker.trigger_hover') || 'On Hover'}
                                </SingleSelect.Option>
                            </SingleSelect>
                        </div>
                    </div>

                    <div className={styles.infoFootnote}>
                        <Icon name="su-info-circle" />
                        <span>
                            {translate('sulu_block_suite.hotspot_picker.footnote')
                                || 'Click or drag on the image canvas to adjust position. All values save automatically.'}
                        </span>
                    </div>
                </div>
            </div>
        );
    }
}
