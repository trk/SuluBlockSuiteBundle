// @flow
import {isArrayLike} from 'mobx';
import jsonpointer from 'json-pointer';

function enrichBlockObject(blockData: Object): Object {
    const propsObj = (blockData.props && typeof blockData.props === 'object' && !isArrayLike(blockData.props))
        ? blockData.props
        : {};

    return {
        ...propsObj,
        ...blockData,
        props: propsObj,
    };
}

export default function blockConditionDataProvider(
    data: Object,
    dataPath: ?string
): {[string]: any} {
    if (!dataPath || !data || typeof data !== 'object') {
        return {};
    }

    let cursorPath = dataPath.startsWith('/') ? dataPath : '/' + dataPath;
    const parentChain = [];
    const blockChain = [];

    if (!cursorPath.endsWith('/props') && jsonpointer.has(data, cursorPath)) {
        const initialEvaluated = jsonpointer.get(data, cursorPath);
        if (
            initialEvaluated
            && typeof initialEvaluated === 'object'
            && !isArrayLike(initialEvaluated)
            && typeof initialEvaluated.type === 'string'
        ) {
            const enrichedInitial = enrichBlockObject(initialEvaluated);
            parentChain.push(enrichedInitial);
            blockChain.push({...enrichedInitial});
        }
    }

    while (cursorPath.includes('/')) {
        cursorPath = cursorPath.substring(0, cursorPath.lastIndexOf('/'));
        if (cursorPath === '') {
            parentChain.push(enrichBlockObject(data));
            break;
        }

        // Skip intermediate `/props` container segment so `__parent` resolves to the enclosing block
        // while still allowing nested blocks inside `/props/*` (e.g. `/props/dynamic_mapping/0/target_field`)
        if (cursorPath.endsWith('/props')) {
            continue;
        }

        if (!jsonpointer.has(data, cursorPath)) {
            continue;
        }

        const evaluated = jsonpointer.get(data, cursorPath);
        if (!evaluated || typeof evaluated !== 'object' || isArrayLike(evaluated)) {
            continue;
        }

        const enriched = enrichBlockObject(evaluated);
        parentChain.push(enriched);

        if (typeof evaluated.type === 'string') {
            blockChain.push({...enriched});
        }
    }

    const result: {[string]: any} = {};

    if (blockChain.length > 0) {
        const nearestBlock = blockChain[0];
        Object.keys(nearestBlock).forEach((key) => {
            if (!key.startsWith('__')) {
                result[key] = nearestBlock[key];
            }
        });
        result.props = nearestBlock.props;
    }

    if (parentChain.length > 0) {
        const rootParent = {...parentChain[0]};
        let currentParent = rootParent;
        for (let i = 1; i < parentChain.length; i++) {
            currentParent.__parent = {...parentChain[i]};
            currentParent = currentParent.__parent;
        }
        currentParent.__parent = null;
        result.__parent = rootParent;
    }

    if (blockChain.length > 0) {
        const rootBlock = {...blockChain[0]};
        let currentBlock = rootBlock;
        for (let i = 1; i < blockChain.length; i++) {
            currentBlock.__parent = {...blockChain[i]};
            currentBlock = currentBlock.__parent;
        }
        currentBlock.__parent = null;
        result.__block = rootBlock;
    }

    return result;
}
