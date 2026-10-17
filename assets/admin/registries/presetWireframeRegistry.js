// @flow
import type {ComponentType} from 'react';

type WireframeProps = {
    componentType: string,
    preset: Object,
};

class PresetWireframeRegistry {
    wireframes: {[string]: ComponentType<WireframeProps>};

    constructor() {
        this.clear();
    }

    clear() {
        this.wireframes = {};
    }

    add(name: string, wireframe: ComponentType<WireframeProps>) {
        if (name in this.wireframes) {
            throw new Error('The key "' + name + '" has already been used for another preset wireframe');
        }

        this.wireframes[name] = wireframe;
    }

    get(name: string): ComponentType<WireframeProps> {
        if (!(name in this.wireframes)) {
            throw new Error(
                'There is no preset wireframe with key "' + name + '" registered.' +
                '\n\nRegistered keys: ' + Object.keys(this.wireframes).sort().join(', ')
            );
        }

        return this.wireframes[name];
    }

    has(name: string): boolean {
        return name in this.wireframes;
    }
}

export default new PresetWireframeRegistry();
